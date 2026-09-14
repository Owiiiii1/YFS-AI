<?php

namespace App\Services\Facebook;

use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FacebookPageAccount;
use App\Services\Ai\AiReplyGenerator;
use App\Services\Bot\BotDecisionTraceService;
use App\Services\Bot\BotMessageTemplateRenderer;
use App\Services\Bot\EventDateGuard;
use App\Services\Bot\ParticipationLocationService;
use App\Services\Instagram\BotConversationContextBuilder;
use App\Services\Instagram\BotFollowUpService;
use App\Services\Instagram\ConversationLanguageResolver;
use App\Services\Instagram\InstagramAttachmentDownloader;
use App\Services\Messaging\MissedBotReplyRetryTracker;
use App\Services\Messaging\ProcessesInboundMessageBatches;
use App\Services\Meta\MetaFacebookMessageSender;
use App\Services\Meta\MetaFacebookOAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIncomingFacebookMessageService
{
    use ProcessesInboundMessageBatches;

    public function __construct(
        private readonly MetaFacebookMessageSender $messageSender,
        private readonly AiReplyGenerator $aiReplyGenerator,
        private readonly MetaFacebookOAuthService $facebookOAuthService,
        private readonly BotConversationContextBuilder $contextBuilder,
        private readonly ConversationLanguageResolver $languageResolver,
        private readonly InstagramAttachmentDownloader $attachmentDownloader,
        private readonly BotFollowUpService $followUpService,
        private readonly MissedBotReplyRetryTracker $retryTracker,
        private readonly BotMessageTemplateRenderer $templates,
        private readonly BotDecisionTraceService $decisionTraces,
        private readonly ParticipationLocationService $location,
        private readonly EventDateGuard $eventDates,
    ) {}

    /**
     * @param  array{
     *     sender_id: string,
     *     message_id: string,
     *     text: string,
     *     timestamp?: int|string|null,
     *     attachments?: list<array{type?: string, url?: string, mime?: string|null}>,
     *     auto_reply?: bool
     * }  $payload
     */
    public function handle(array $payload): void
    {
        $account = FacebookPageAccount::primary();
        if (! $account->isConnected()) {
            return;
        }

        $senderId = trim((string) ($payload['sender_id'] ?? ''));
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        $text = trim((string) ($payload['text'] ?? ''));
        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        $autoReply = array_key_exists('auto_reply', $payload)
            ? (bool) $payload['auto_reply']
            : true;

        if ($senderId === '' || $messageId === '') {
            return;
        }

        if (ConversationMessage::query()->where('external_id', $messageId)->exists()) {
            return;
        }

        $sentAt = $this->resolveTimestamp($payload['timestamp'] ?? null);

        $conversation = Conversation::query()->firstOrCreate(
            [
                'channel' => 'facebook',
                'participant_id' => $senderId,
            ],
            [
                'status' => Conversation::STATUS_OPEN,
                'bot_enabled' => true,
                'last_message_at' => $sentAt,
            ],
        );

        $this->hydrateParticipantProfile($conversation, $account, $senderId);
        $this->contextBuilder->linkCustomer($conversation->fresh());

        $attachmentPath = null;
        $attachmentMime = null;
        $attachmentDownloadFailed = false;
        if ($attachments !== []) {
            try {
                $downloaded = $this->attachmentDownloader->download(
                    $attachments[0],
                    $conversation->id,
                    $messageId,
                    (string) $account->access_token_encrypted,
                );
                $attachmentPath = $downloaded['path'];
                $attachmentMime = $downloaded['mime'];
            } catch (Throwable $exception) {
                $attachmentDownloadFailed = true;
                Log::warning('Facebook attachment download failed.', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $inboundMessage = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'external_id' => $messageId,
            'direction' => ConversationMessage::DIRECTION_INBOUND,
            'sender_type' => ConversationMessage::SENDER_CUSTOMER,
            'body' => $text !== '' ? $text : '[Facebook image attached]',
            'attachment_path' => $attachmentPath,
            'attachment_mime' => $attachmentMime,
            'sent_at' => $sentAt,
        ]);

        Cache::lock('facebook-conversation-'.$conversation->id, 180)->block(120, function () use (
            $conversation,
            $account,
            $senderId,
            $text,
            $attachmentPath,
            $attachmentMime,
            $attachmentDownloadFailed,
            $inboundMessage,
            $sentAt,
            $autoReply,
        ): void {
            $conversation->forceFill([
                'last_message_at' => ($conversation->last_message_at === null || $sentAt->gte($conversation->last_message_at))
                    ? $sentAt
                    : $conversation->last_message_at,
                'status' => $conversation->status === Conversation::STATUS_CLOSED
                    ? Conversation::STATUS_OPEN
                    : $conversation->status,
                'bot_awaits_reply' => false,
                'bot_awaiting_topic' => null,
                'bot_awaiting_since' => null,
                'bot_reminder_sent_at' => null,
            ])->save();

            if (! $autoReply) {
                $this->decisionTraces->recordSkipped($conversation, $inboundMessage, $text, 'auto_reply_disabled_for_ingest');
                return;
            }

            if ($this->hasOutboundAfterInbound($conversation, $inboundMessage)) {
                $this->decisionTraces->recordSkipped($conversation, $inboundMessage, $text, 'outbound_already_exists');
                Log::info('Facebook auto-reply skipped: outbound already exists after inbound.', [
                    'conversation_id' => $conversation->id,
                    'inbound_id' => $inboundMessage->id,
                ]);

                return;
            }

            if (! $this->shouldAutoReply($conversation, $account)) {
                $this->decisionTraces->recordSkipped($conversation, $inboundMessage, $text, 'bot_or_channel_unavailable');
                return;
            }

            $this->decisionTraces->begin($conversation, $inboundMessage, $text);
            try {
                $this->produceAutoReply(
                    $account,
                    $conversation,
                    $senderId,
                    $inboundMessage,
                    $text,
                    $attachmentPath,
                    $attachmentMime,
                    $attachmentDownloadFailed,
                    $sentAt,
                    waitForBurst: true,
                );
                $this->retryTracker->clear($inboundMessage);
            } catch (Throwable $exception) {
                BotDecisionTraceService::failed($exception);
                Log::warning('Facebook bot auto-reply failed.', [
                    'conversation_id' => $conversation->id,
                    'inbound_id' => $inboundMessage->id,
                    'message' => $exception->getMessage(),
                ]);

                if ($this->hasOutboundAfterInbound($conversation, $inboundMessage)) {
                    $this->retryTracker->clear($inboundMessage);
                } else {
                    $this->retryTracker->markFailed($inboundMessage);
                }
            } finally {
                BotDecisionTraceService::finishIfRunning();
                BotDecisionTraceService::clear();
            }
        });
    }

    public function retryLatestUnansweredInbound(Conversation $conversation, bool $force = false): bool
    {
        $account = FacebookPageAccount::primary();
        if (! $account->isConnected()) {
            return false;
        }

        $lock = Cache::lock('facebook-conversation-'.$conversation->id, 180);
        if (! $lock->get()) {
            return false;
        }

        try {
            return $this->retryLatestUnansweredInboundLocked($account, $conversation, $force);
        } finally {
            $lock->release();
        }
    }

    private function retryLatestUnansweredInboundLocked(
        FacebookPageAccount $account,
        Conversation $conversation,
        bool $force = false,
    ): bool {
        $conversation->refresh();

        if (! $this->shouldAutoReply($conversation, $account)) {
            return false;
        }

        $inboundMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($inboundMessage === null || $inboundMessage->direction !== ConversationMessage::DIRECTION_INBOUND) {
            return false;
        }

        $sentAt = $this->retryTracker->sentAt($inboundMessage);

        if ($this->hasOutboundAfterInbound($conversation, $inboundMessage)) {
            $this->retryTracker->clear($inboundMessage);

            return false;
        }

        if (! $force && ! $this->retryTracker->canRetry($inboundMessage)) {
            return false;
        }

        $text = $this->inboundBodyForReply($inboundMessage);
        $this->decisionTraces->begin($conversation, $inboundMessage, $text);
        try {
            $this->produceAutoReply(
                $account,
                $conversation,
                (string) $conversation->participant_id,
                $inboundMessage,
                $text,
                $inboundMessage->attachment_path,
                $inboundMessage->attachment_mime,
                false,
                $sentAt,
                waitForBurst: false,
            );
            $this->retryTracker->clear($inboundMessage);

            return $this->hasOutboundAfterInbound($conversation, $inboundMessage);
        } catch (Throwable $exception) {
            BotDecisionTraceService::failed($exception);
            Log::warning('Facebook bot auto-reply retry failed.', [
                'conversation_id' => $conversation->id,
                'inbound_id' => $inboundMessage->id,
                'message' => $exception->getMessage(),
            ]);
            $this->retryTracker->markFailed($inboundMessage);

            return false;
        } finally {
            BotDecisionTraceService::finishIfRunning();
            BotDecisionTraceService::clear();
        }
    }

    private function produceAutoReply(
        FacebookPageAccount $account,
        Conversation $conversation,
        string $senderId,
        ConversationMessage $inboundMessage,
        string $text,
        ?string $attachmentPath,
        ?string $attachmentMime,
        bool $attachmentDownloadFailed,
        Carbon $sentAt,
        bool $waitForBurst,
    ): void {
        $conversation->refresh();

        if ($waitForBurst && ! $this->shouldReplyAsBatchLeader($conversation, $inboundMessage)) {
            Log::info('Facebook auto-reply deferred: newer inbound in burst.', [
                'conversation_id' => $conversation->id,
                'inbound_id' => $inboundMessage->id,
            ]);

            BotDecisionTraceService::finishIfRunning('deferred', 'newer_inbound_in_burst');
            return;
        }

        if ($this->hasNewerInboundThan($conversation, $inboundMessage)) {
            BotDecisionTraceService::finishIfRunning('deferred', 'newer_inbound_exists');
            return;
        }

        if ($this->hasOutboundAfterInbound($conversation, $inboundMessage)) {
            BotDecisionTraceService::finishIfRunning('skipped', 'outbound_already_exists');
            return;
        }

        $effectiveText = $this->assemblePendingInboundBatch($conversation);
        if ($effectiveText === '') {
            $effectiveText = $text !== '' ? $text : 'Customer sent an inspiration image.';
        }
        BotDecisionTraceService::context(
            $conversation,
            $effectiveText,
            $this->languageResolver->resolveFromConversation($conversation, $effectiveText),
        );
        BotDecisionTraceService::step('batch_assembled');

        $formConfig = BotSetting::instance()->prompt_config ?? [];
        $formConfig = is_array($formConfig) ? $formConfig : [];
        $this->location->rememberFromCustomer($conversation, $effectiveText);

        $replyText = $this->aiReplyGenerator->generate(
            $this->contextBuilder->buildSystemPrompt($conversation, $effectiveText),
            $this->contextBuilder->buildUserPrompt($conversation, $effectiveText),
            trace: [
                'conversation_id' => $conversation->id,
                'purpose' => 'reply_generate',
            ],
        );

        if ($this->staleBecauseNewerInbound($conversation, $inboundMessage, 'Facebook')) {
            return;
        }

        $this->sendBotReply(
            $account,
            $conversation,
            $senderId,
            $this->eventDates->sanitize(
                $this->location->gateReply($conversation->fresh(), $replyText, $formConfig, $effectiveText),
                $conversation,
                $effectiveText,
            ),
            awaitsReply: true,
        );
    }

    private function inboundBodyForReply(ConversationMessage $message): string
    {
        $text = trim((string) $message->body);

        if ($text === '[Facebook image attached]') {
            return '';
        }

        return $text;
    }

    private function shouldAutoReply(Conversation $conversation, FacebookPageAccount $account): bool
    {
        if (! BotSetting::instance()->bot_enabled) {
            return false;
        }

        if (! (bool) data_get($account->settings, 'bot_enabled', true)) {
            return false;
        }

        if (! $conversation->bot_enabled) {
            return false;
        }

        if (in_array($conversation->status, [
            Conversation::STATUS_PENDING_HUMAN,
            Conversation::STATUS_ORDER_IN_PROGRESS,
        ], true)) {
            return false;
        }

        if (! $this->messageSender->canSend($account)) {
            return false;
        }

        return true;
    }

    private function hasOutboundAfterInbound(Conversation $conversation, ConversationMessage $inboundMessage): bool
    {
        $sentAt = $this->retryTracker->sentAt($inboundMessage);

        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->where(function ($query) use ($inboundMessage, $sentAt): void {
                $query->where('sent_at', '>', $sentAt)
                    ->orWhere(function ($sameSecond) use ($inboundMessage, $sentAt): void {
                        $sameSecond->where('sent_at', $sentAt)
                            ->where('id', '>', $inboundMessage->id);
                    });
            })
            ->exists();
    }

    private function sendBotReply(
        FacebookPageAccount $account,
        Conversation $conversation,
        string $senderId,
        string $replyText,
        bool $awaitsReply = false,
        ?string $awaitingTopic = null,
    ): void {
        $chunks = $this->messageSender->splitText($replyText);
        $outboundIds = $this->messageSender->sendTextMessage($account, $senderId, $replyText);
        $sentAt = Carbon::now();
        $savedMessageIds = [];

        foreach ($chunks as $index => $chunk) {
            $saved = ConversationMessage::query()->create([
                'conversation_id' => $conversation->id,
                'external_id' => filled($outboundIds[$index] ?? null) ? $outboundIds[$index] : null,
                'direction' => ConversationMessage::DIRECTION_OUTBOUND,
                'sender_type' => ConversationMessage::SENDER_BOT,
                'body' => $chunk,
                'sent_at' => $sentAt,
                'read_at' => $sentAt,
            ]);
            $savedMessageIds[] = $saved->id;
        }

        $conversation->forceFill(['last_message_at' => $sentAt])->save();

        if ($awaitsReply) {
            $this->followUpService->markAwaitingReply($conversation->fresh(), $replyText, $awaitingTopic);
        } else {
            $this->followUpService->clearAwaitingReply($conversation->fresh());
        }

        BotDecisionTraceService::sent($savedMessageIds);
    }

    private function moveToHuman(Conversation $conversation): void
    {
        $conversation->forceFill([
            'status' => Conversation::STATUS_PENDING_HUMAN,
            'bot_enabled' => false,
        ])->save();

        $this->followUpService->clearAwaitingReply($conversation->fresh());
    }

    private function humanHandoffReply(Conversation $conversation, string $latestMessage): string
    {
        $language = $this->languageResolver->resolveFromConversation($conversation, $latestMessage);

        return $this->templates->render('human_handoff', $language);
    }

    public function hydrateParticipantProfile(Conversation $conversation, FacebookPageAccount $account, string $senderId): void
    {
        if (
            filled($conversation->participant_username)
            && filled($conversation->participant_name)
            && ! $conversation->participantUsernameMatchesAccount()
        ) {
            return;
        }

        if (
            filled($conversation->participant_username)
            && ! $conversation->participantUsernameMatchesAccount()
        ) {
            return;
        }

        try {
            $token = (string) $account->access_token_encrypted;
            $profile = $this->facebookOAuthService->getMessengerUserProfile($token, $senderId);

            if ($this->profileBelongsToAccount($account, $profile, $senderId)) {
                return;
            }

            $name = trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                $name = trim(((string) ($profile['first_name'] ?? '')).' '.((string) ($profile['last_name'] ?? '')));
            }
            $conversation->forceFill([
                'participant_username' => $conversation->participant_username ?: $senderId,
                'participant_name' => $name !== '' ? $name : $conversation->participant_name,
            ])->save();

            $this->contextBuilder->linkCustomer($conversation->fresh());
        } catch (\RuntimeException) {
            // Profile lookup is best-effort for customer IGSID.
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function profileBelongsToAccount(FacebookPageAccount $account, array $profile, string $senderId): bool
    {
        $profileId = trim((string) ($profile['id'] ?? ''));
        $pageId = trim((string) $account->facebook_page_id);

        return $pageId !== '' && ($profileId === $pageId || $senderId === $pageId);
    }

    private function resolveTimestamp(mixed $timestamp): Carbon
    {
        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;

            return $value > 9999999999
                ? Carbon::createFromTimestampMs($value)
                : Carbon::createFromTimestamp($value);
        }

        return Carbon::now();
    }
}
