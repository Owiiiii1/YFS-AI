<?php

namespace App\Http\Controllers;

use App\Models\AiPromptAnalysisMessage;
use App\Models\AiPromptAnalysisSession;
use App\Models\AiPromptChangeProposal;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Services\Ai\AiAnalysisSessionStateMachine;
use App\Services\Ai\AiPromptAnalysisService;
use App\Services\Bot\BotPromptApplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiPromptAnalysisController extends Controller
{
    public function __construct(
        private readonly AiPromptAnalysisService $analysis,
        private readonly AiAnalysisSessionStateMachine $state,
        private readonly BotPromptApplyService $promptApply,
    ) {}

    public function active(Request $request): JsonResponse
    {
        $session = AiPromptAnalysisSession::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', [AiPromptAnalysisSession::STATUS_FINISHED])
            ->latest('updated_at')
            ->first();

        return response()->json(['session' => $session ? $this->present($session) : null]);
    }

    public function history(Request $request): JsonResponse
    {
        $sessions = AiPromptAnalysisSession::query()
            ->with('conversation:id,channel,participant_username')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (AiPromptAnalysisSession $session): array => [
                'id' => $session->id,
                'question' => $session->question,
                'status' => $session->status,
                'step' => $session->step,
                'cause_category' => $session->analysis_payload['cause_category'] ?? null,
                'conversation' => $session->conversation ? [
                    'username' => $session->conversation->participant_username,
                    'channel' => $session->conversation->channel,
                ] : null,
                'updated_at' => optional($session->updated_at)?->toIso8601String(),
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:10000'],
            'parent_session_id' => ['nullable', 'integer', 'exists:ai_prompt_analysis_sessions,id'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ]);
        $setting = BotSetting::instance();
        $conversation = isset($validated['conversation_id'])
            ? Conversation::query()->find($validated['conversation_id'])
            : null;
        if ($conversation === null && isset($validated['parent_session_id'])) {
            $conversation = AiPromptAnalysisSession::query()
                ->find($validated['parent_session_id'])
                ?->conversation;
        }
        $session = AiPromptAnalysisSession::query()->create([
            'user_id' => $request->user()->id,
            'parent_session_id' => $validated['parent_session_id'] ?? null,
            'conversation_id' => $conversation?->id,
            'target_username' => $conversation?->participant_username,
            'status' => AiPromptAnalysisSession::STATUS_DRAFT,
            'step' => 1,
            'question' => $validated['question'],
            'bot_revision_at_start' => (int) $setting->prompt_revision,
        ]);
        AiPromptAnalysisMessage::query()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'stage' => 1,
            'body' => $session->question,
        ]);

        return response()->json(['session' => $this->present($session)], 201);
    }

    public function show(AiPromptAnalysisSession $session): JsonResponse
    {
        return response()->json(['session' => $this->present($session)]);
    }

    public function analyze(AiPromptAnalysisSession $session): JsonResponse
    {
        $this->state->require($session, [
            AiPromptAnalysisSession::STATUS_DRAFT,
            AiPromptAnalysisSession::STATUS_FAILED,
        ], 'анализ');
        $this->state->move($session, AiPromptAnalysisSession::STATUS_ANALYZING, 2);

        try {
            $this->analysis->analyze($session);
            $this->state->move($session, AiPromptAnalysisSession::STATUS_ANALYZED, 3);
        } catch (Throwable $exception) {
            $session->forceFill([
                'status' => AiPromptAnalysisSession::STATUS_FAILED,
                'step' => 2,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        return response()->json(['session' => $this->present($session)]);
    }

    public function instruction(Request $request, AiPromptAnalysisSession $session): JsonResponse
    {
        $this->state->require($session, [
            AiPromptAnalysisSession::STATUS_ANALYZED,
            AiPromptAnalysisSession::STATUS_PREVIEW_READY,
        ], 'уточнение исправления');
        $validated = $request->validate([
            'instruction' => ['required', 'string', 'max:10000'],
        ]);
        $session->forceFill([
            'user_instruction' => $validated['instruction'],
            'status' => AiPromptAnalysisSession::STATUS_ANALYZED,
            'step' => 4,
        ])->save();
        $session->proposals()
            ->where('status', 'preview_ready')
            ->update(['status' => 'superseded']);
        AiPromptAnalysisMessage::query()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'stage' => 3,
            'body' => $validated['instruction'],
        ]);

        return response()->json(['session' => $this->present($session)]);
    }

    public function preview(AiPromptAnalysisSession $session): JsonResponse
    {
        $this->state->require($session, [AiPromptAnalysisSession::STATUS_ANALYZED], 'формирование preview');
        if (blank($session->user_instruction)) {
            throw ValidationException::withMessages(['instruction' => 'Сначала опишите, что нужно исправить.']);
        }

        $this->analysis->prepareProposal($session);
        $this->state->move($session, AiPromptAnalysisSession::STATUS_PREVIEW_READY, 5);

        return response()->json(['session' => $this->present($session)]);
    }

    public function approve(Request $request, AiPromptAnalysisSession $session): JsonResponse
    {
        $this->state->require($session, [AiPromptAnalysisSession::STATUS_PREVIEW_READY], 'подтверждение');
        $proposal = $session->proposals()->latest('id')->firstOrFail();
        if (($proposal->validation_results['valid'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'proposal' => 'Предложение можно изучить, но нельзя применить, пока не устранены ошибки проверки.',
            ]);
        }

        DB::transaction(function () use ($proposal, $session, $request): void {
            $locked = AiPromptChangeProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->status !== 'preview_ready') {
                throw ValidationException::withMessages(['proposal' => 'Это предложение уже обработано.']);
            }
            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();
            $this->state->move($session, AiPromptAnalysisSession::STATUS_APPROVED, 6);
        });

        return response()->json(['session' => $this->present($session)]);
    }

    public function apply(Request $request, AiPromptAnalysisSession $session): JsonResponse
    {
        $this->state->require($session, [
            AiPromptAnalysisSession::STATUS_APPROVED,
            AiPromptAnalysisSession::STATUS_APPLIED,
        ], 'применение');
        $proposal = $session->proposals()->latest('id')->firstOrFail();

        if ($proposal->status === 'applied') {
            return response()->json(['session' => $this->present($session)]);
        }

        $setting = BotSetting::instance();
        if ((int) $setting->prompt_revision !== (int) $proposal->base_revision) {
            $proposal->forceFill(['status' => 'stale'])->save();
            $session->forceFill([
                'status' => AiPromptAnalysisSession::STATUS_ANALYZED,
                'step' => 3,
                'bot_revision_at_start' => (int) $setting->prompt_revision,
                'error_message' => 'Промпт изменился. Preview нужно сформировать заново.',
            ])->save();

            return response()->json([
                'message' => $session->error_message,
                'session' => $this->present($session),
            ], 409);
        }

        $updated = $this->promptApply->apply(
            (array) $proposal->preview_after,
            (int) $proposal->base_revision,
            $request->user()->id,
            'AI prompt analysis proposal #'.$proposal->id,
        );
        $proposal->forceFill([
            'status' => 'applied',
            'applied_revision' => (int) $updated->prompt_revision,
            'applied_at' => now(),
        ])->save();
        $session->forceFill([
            'status' => AiPromptAnalysisSession::STATUS_APPLIED,
            'step' => 7,
            'context_summary' => trim(implode("\n", array_filter([
                $session->context_summary,
                'Исправление: '.$session->user_instruction,
                'Применена ревизия '.(int) $updated->prompt_revision.'.',
            ]))),
        ])->save();
        AiPromptAnalysisMessage::query()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'stage' => 6,
            'body' => 'Изменение атомарно применено. Активная ревизия: '.$updated->prompt_revision.'.',
            'payload' => ['applied_revision' => $updated->prompt_revision],
        ]);

        return response()->json(['session' => $this->present($session)]);
    }

    public function finish(AiPromptAnalysisSession $session): JsonResponse
    {
        $session->forceFill([
            'status' => AiPromptAnalysisSession::STATUS_FINISHED,
            'step' => 7,
            'finished_at' => now(),
        ])->save();

        return response()->json(['session' => $this->present($session)]);
    }

    public function searchDialogs(Request $request): JsonResponse
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:64']]);
        $needle = ltrim(mb_strtolower($validated['username']), '@');
        $conversations = Conversation::query()
            ->whereRaw('LOWER(participant_username) LIKE ?', ['%'.$needle.'%'])
            ->latest('last_message_at')
            ->limit(10)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'username' => $conversation->participant_username,
                'channel' => $conversation->channel,
                'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
            ]);

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiPromptAnalysisSession $session): array
    {
        $session->load([
            'conversation:id,channel,participant_username',
            'messages' => fn ($query) => $query->orderBy('id'),
            'proposals' => fn ($query) => $query->latest('id')->limit(1),
        ]);
        $proposal = $session->proposals->first();

        return [
            'id' => $session->id,
            'parent_session_id' => $session->parent_session_id,
            'status' => $session->status,
            'step' => $session->step,
            'question' => $session->question,
            'target_username' => $session->target_username,
            'conversation' => $session->conversation ? [
                'id' => $session->conversation->id,
                'channel' => $session->conversation->channel,
                'username' => $session->conversation->participant_username,
            ] : null,
            'analysis' => $session->analysis_payload,
            'instruction' => $session->user_instruction,
            'error' => $session->error_message,
            'messages' => $session->messages->map(fn ($message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'stage' => $message->stage,
                'body' => $message->body,
                'payload' => $message->payload,
            ])->all(),
            'proposal' => $proposal ? [
                'id' => $proposal->id,
                'status' => $proposal->status,
                'base_revision' => $proposal->base_revision,
                'patch' => $proposal->proposed_patch,
                'diff' => $proposal->validation_results['diff'] ?? [],
                'validation' => $proposal->validation_results,
                'sensitive_changes' => $proposal->sensitive_changes ?? [],
                'applied_revision' => $proposal->applied_revision,
            ] : null,
            'updated_at' => optional($session->updated_at)?->toIso8601String(),
        ];
    }
}
