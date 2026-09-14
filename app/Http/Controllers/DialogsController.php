<?php

namespace App\Http\Controllers;

use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FacebookPageAccount;
use App\Models\InstagramAccount;
use App\Services\Instagram\BotFollowUpService;
use App\Services\Instagram\ConversationLanguageResolver;
use App\Services\Meta\MetaFacebookMessageSender;
use App\Services\Meta\MetaInstagramMessageSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class DialogsController extends Controller
{
    public function __construct(
        private readonly MetaInstagramMessageSender $instagramSender,
        private readonly MetaFacebookMessageSender $facebookSender,
        private readonly BotFollowUpService $followUpService,
        private readonly ConversationLanguageResolver $languageResolver,
    ) {}

    public function index(Request $request): Response
    {
        $channel = $this->resolveChannel($request);

        $conversations = $this->conversationsQuery($channel)
            ->get()
            ->map(fn (Conversation $conversation): array => $this->formatConversation($conversation))
            ->all();

        $activeConversation = null;
        $messages = [];

        if ($request->filled('conversation')) {
            $conversation = Conversation::query()->find($request->integer('conversation'));

            if ($conversation && $conversation->channel === $channel) {
                $this->markConversationRead($conversation);
                $activeConversation = $this->formatConversation(
                    $conversation->fresh()->loadCount([
                        'messages',
                        'messages as unread_count' => static fn ($query) => $query
                            ->where('direction', ConversationMessage::DIRECTION_INBOUND)
                            ->whereNull('read_at'),
                    ]),
                );
                $messages = $this->loadMessages($conversation);
                $conversations = $this->conversationsQuery($channel)
                    ->get()
                    ->map(fn (Conversation $item): array => $this->formatConversation($item))
                    ->all();
            }
        }

        return Inertia::render('Dialogs/Index', [
            'channel' => $channel,
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'messages' => $messages,
        ]);
    }

    public function storeMessage(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'image', 'max:5120'],
        ]);

        if (blank($validated['body'] ?? null) && ! $request->hasFile('attachment')) {
            return back()->withErrors([
                'message' => 'Message text or image is required.',
            ]);
        }

        $attachmentPath = null;
        $attachmentMime = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store('conversation-attachments/'.$conversation->id, 'public');
            $attachmentMime = $file->getMimeType();
        }

        $sentAt = Carbon::now();
        $outboundIds = [];
        $bodyChunks = filled($validated['body'] ?? null)
            ? [$validated['body']]
            : [null];

        if (filled($validated['body'] ?? null)) {
            try {
                if ($conversation->channel === 'instagram') {
                    $account = InstagramAccount::primary();
                    $formConfig = BotSetting::instance()->prompt_config ?? [];
                    $locale = $this->languageResolver->resolveFromConversation($conversation);
                    $parts = $this->instagramSender->sendReplyWithFormButtons(
                        $account,
                        (string) $conversation->participant_id,
                        (string) $validated['body'],
                        $locale,
                        true,
                        is_array($formConfig) ? $formConfig : [],
                    );
                    $bodyChunks = array_map(
                        static fn (array $part): string => (string) $part['body'],
                        $parts,
                    );
                    $outboundIds = array_map(
                        static fn (array $part): string => (string) ($part['id'] ?? ''),
                        $parts,
                    );
                } elseif ($conversation->channel === 'facebook') {
                    $account = FacebookPageAccount::primary();
                    $bodyChunks = $this->facebookSender->splitText((string) $validated['body']);
                    $outboundIds = $this->facebookSender->sendTextMessage(
                        $account,
                        (string) $conversation->participant_id,
                        (string) $validated['body'],
                        asHumanAgent: true,
                    );
                }
            } catch (RuntimeException $exception) {
                return back()->withErrors([
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($bodyChunks as $index => $chunk) {
            ConversationMessage::query()->create([
                'conversation_id' => $conversation->id,
                'external_id' => filled($outboundIds[$index] ?? null) ? $outboundIds[$index] : null,
                'direction' => ConversationMessage::DIRECTION_OUTBOUND,
                'sender_type' => ConversationMessage::SENDER_OPERATOR,
                'sent_via' => ConversationMessage::SENT_VIA_CRM,
                'body' => $chunk,
                'attachment_path' => $index === 0 ? $attachmentPath : null,
                'attachment_mime' => $index === 0 ? $attachmentMime : null,
                'read_at' => $sentAt,
                'sent_at' => $sentAt,
            ]);
        }

        $conversation->forceFill([
            'last_message_at' => $sentAt,
            'status' => $conversation->status === Conversation::STATUS_PENDING_HUMAN
                ? Conversation::STATUS_OPEN
                : $conversation->status,
        ])->save();

        $this->followUpService->clearAwaitingReply($conversation->fresh());

        return redirect()->route(
            $conversation->channel === 'facebook' ? 'dialogs.facebook' : 'dialogs.instagram',
            ['conversation' => $conversation->id],
        );
    }

    public function updateBot(Request $request, Conversation $conversation): RedirectResponse
    {
        $request->validate([
            'bot_enabled' => ['required', 'boolean'],
        ]);

        $botEnabled = $request->boolean('bot_enabled');
        $updates = [
            'bot_enabled' => $botEnabled,
            'bot_manual_mode_at' => $botEnabled ? null : Carbon::now(),
        ];

        if ($botEnabled && $conversation->status === Conversation::STATUS_PENDING_HUMAN) {
            $updates['status'] = Conversation::STATUS_OPEN;
        }

        $conversation->forceFill($updates)->save();

        if (! $botEnabled) {
            $this->followUpService->clearAwaitingReply($conversation->fresh());
        }

        return redirect()->route(
            $conversation->channel === 'facebook' ? 'dialogs.facebook' : 'dialogs.instagram',
            ['conversation' => $conversation->id],
        );
    }

    private function resolveChannel(Request $request): string
    {
        if ($request->routeIs('dialogs.facebook')) {
            return 'facebook';
        }

        return 'instagram';
    }

    private function markConversationRead(Conversation $conversation): void
    {
        ConversationMessage::markInboundRead((int) $conversation->id);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Conversation>
     */
    private function conversationsQuery(string $channel)
    {
        $latestMessageAt = ConversationMessage::query()
            ->selectRaw('MAX(sent_at)')
            ->whereColumn('conversation_messages.conversation_id', 'conversations.id');

        return Conversation::query()
            ->where('channel', $channel)
            ->with('customer:id,name,instagram_username')
            ->withCount([
                'messages',
                'messages as unread_count' => static fn ($query) => $query
                    ->where('direction', ConversationMessage::DIRECTION_INBOUND)
                    ->whereNull('read_at'),
            ])
            ->orderByDesc($latestMessageAt)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadMessages(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'sender_type' => $message->sender_type,
                'sent_via' => $message->sent_via,
                'sender_label' => $message->aiContextLabel(),
                'body' => $message->body,
                'attachment_url' => $message->attachmentUrl(),
                'sent_at' => optional($message->sent_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'display_name' => $conversation->displayName(),
            'participant_username' => $conversation->displayUsername(),
            'status' => $conversation->status,
            'requires_operator' => $conversation->requiresOperator(),
            'bot_enabled' => (bool) $conversation->bot_enabled,
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'unread_count' => (int) ($conversation->unread_count ?? 0),
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
        ];
    }
}
