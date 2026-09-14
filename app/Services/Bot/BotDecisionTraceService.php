<?php

namespace App\Services\Bot;

use App\Models\BotDecisionTrace;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;

class BotDecisionTraceService
{
    private static ?int $currentTraceId = null;

    public function begin(
        Conversation $conversation,
        ConversationMessage $inboundMessage,
        string $customerText,
    ): BotDecisionTrace {
        $setting = BotSetting::instance();
        $trace = BotDecisionTrace::query()->create([
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
            'trigger_inbound_message_id' => $inboundMessage->id,
            'trigger_inbound_message_ids' => [$inboundMessage->id],
            'effective_customer_text' => mb_substr($customerText, 0, 20000),
            'status' => 'running',
            'decision_path' => ['received'],
            'conversation_status' => $conversation->status,
            'intake_status' => $conversation->intake_status,
            'intake_data_snapshot' => $conversation->intake_data,
            'bot_prompt_revision' => (int) $setting->prompt_revision,
            'bot_enabled' => (bool) $conversation->bot_enabled,
        ]);

        self::$currentTraceId = $trace->id;

        return $trace;
    }

    public function beginFollowUp(Conversation $conversation): BotDecisionTrace
    {
        $setting = BotSetting::instance();
        $trace = BotDecisionTrace::query()->create([
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
            'status' => 'running',
            'reply_source' => 'follow_up',
            'decision_path' => ['follow_up_due'],
            'conversation_status' => $conversation->status,
            'intake_status' => $conversation->intake_status,
            'intake_data_snapshot' => $conversation->intake_data,
            'language' => app(\App\Services\Instagram\ConversationLanguageResolver::class)
                ->resolveFromConversation($conversation),
            'bot_prompt_revision' => (int) $setting->prompt_revision,
            'bot_enabled' => (bool) $conversation->bot_enabled,
        ]);

        self::$currentTraceId = $trace->id;

        return $trace;
    }

    public function recordSkipped(
        Conversation $conversation,
        ConversationMessage $inboundMessage,
        string $customerText,
        string $reason,
    ): void {
        $setting = BotSetting::instance();
        BotDecisionTrace::query()->create([
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
            'trigger_inbound_message_id' => $inboundMessage->id,
            'trigger_inbound_message_ids' => [$inboundMessage->id],
            'effective_customer_text' => mb_substr($customerText, 0, 20000),
            'status' => 'skipped',
            'reply_source' => 'rule',
            'decision_path' => ['received', 'skipped:'.$reason],
            'skip_reason' => $reason,
            'conversation_status' => $conversation->status,
            'intake_status' => $conversation->intake_status,
            'intake_data_snapshot' => $conversation->intake_data,
            'bot_prompt_revision' => (int) $setting->prompt_revision,
            'bot_enabled' => (bool) $conversation->bot_enabled,
            'completed_at' => Carbon::now(),
        ]);
    }

    public static function currentId(): ?int
    {
        return self::$currentTraceId;
    }

    public static function step(string $step, array $metadata = []): void
    {
        $trace = self::current();
        if ($trace === null) {
            return;
        }

        $path = $trace->decision_path ?? [];
        if (! in_array($step, $path, true)) {
            $path[] = $step;
        }
        $trace->forceFill([
            'decision_path' => $path,
            'metadata' => array_merge($trace->metadata ?? [], $metadata),
        ])->save();
    }

    public static function template(string $key): void
    {
        $trace = self::current();
        if ($trace === null) {
            return;
        }

        self::step('template:'.$key);
        $trace->forceFill([
            'reply_source' => 'template',
            'template_key' => $key,
        ])->save();
    }

    /**
     * @param  list<int>  $outboundMessageIds
     */
    public static function sent(array $outboundMessageIds, ?int $orderId = null): void
    {
        $trace = self::current();
        if ($trace === null) {
            return;
        }

        $source = $trace->reply_source;
        if ($source === null) {
            $source = $trace->aiRuns()->where('purpose', 'reply_generate')->exists() ? 'ai' : 'rule';
        }

        $trace->forceFill([
            'status' => 'sent',
            'reply_source' => $source,
            'outbound_message_ids' => $outboundMessageIds,
            'order_id' => $orderId,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    public static function snapshot(Conversation $conversation): void
    {
        $trace = self::current();
        if ($trace === null) {
            return;
        }

        $trace->forceFill([
            'conversation_status' => $conversation->status,
            'intake_status' => $conversation->intake_status,
            'intake_data_snapshot' => $conversation->intake_data,
        ])->save();
    }

    public static function context(Conversation $conversation, string $customerText, ?string $language = null): void
    {
        $trace = self::current();
        if ($trace === null) {
            return;
        }

        $trace->forceFill([
            'effective_customer_text' => mb_substr($customerText, 0, 20000),
            'language' => $language,
            'conversation_status' => $conversation->status,
            'intake_status' => $conversation->intake_status,
            'intake_data_snapshot' => $conversation->intake_data,
        ])->save();
    }

    public static function finishIfRunning(string $status = 'completed_without_reply', ?string $reason = null): void
    {
        $trace = self::current();
        if ($trace === null || $trace->status !== 'running') {
            return;
        }

        $trace->forceFill([
            'status' => $status,
            'skip_reason' => $reason,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    public static function failed(\Throwable $exception): void
    {
        $trace = self::current();
        if ($trace === null) {
            return;
        }

        $trace->forceFill([
            'status' => 'failed',
            'skip_reason' => mb_substr($exception->getMessage(), 0, 255),
            'completed_at' => Carbon::now(),
        ])->save();
    }

    public static function clear(): void
    {
        self::$currentTraceId = null;
    }

    private static function current(): ?BotDecisionTrace
    {
        return self::$currentTraceId === null
            ? null
            : BotDecisionTrace::query()->find(self::$currentTraceId);
    }
}
