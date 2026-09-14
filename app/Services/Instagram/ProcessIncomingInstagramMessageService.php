<?php

namespace App\Services\Instagram;

use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\InstagramAccount;
use App\Services\Ai\AiReplyGenerator;
use App\Services\Bot\BotMessageTemplateRenderer;
use App\Services\Bot\BotDecisionTraceService;
use App\Models\BotReply;
use App\Services\Bot\BotOutcomeService;
use App\Services\Bot\OperatorHandoffRecoveryService;
use App\Services\Bot\EventDateGuard;
use App\Services\Bot\OutboundLinkThrottle;
use App\Services\Bot\ParticipationLocationService;
use App\Services\Bot\YfsIntentRouter;
use App\Services\Jfs\JfsReadService;
use App\Services\Messaging\MissedBotReplyRetryTracker;
use App\Services\Messaging\ProcessesInboundMessageBatches;
use App\Services\Meta\MetaInstagramMessageSender;
use App\Services\Meta\MetaOAuthService;
use App\Support\InstagramOutboundFormButtons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIncomingInstagramMessageService
{
    use ProcessesInboundMessageBatches;

    public function __construct(
        private readonly MetaInstagramMessageSender $messageSender,
        private readonly AiReplyGenerator $aiReplyGenerator,
        private readonly MetaOAuthService $metaOAuthService,
        private readonly BotConversationContextBuilder $contextBuilder,
        private readonly ConversationLanguageResolver $languageResolver,
        private readonly HumanHandoffClassifier $handoffClassifier,
        private readonly InstagramAttachmentDownloader $attachmentDownloader,
        private readonly InstagramMediaResolver $mediaResolver,
        private readonly BotFollowUpService $followUpService,
        private readonly MissedBotReplyRetryTracker $retryTracker,
        private readonly BotMessageTemplateRenderer $templates,
        private readonly BotDecisionTraceService $decisionTraces,
        private readonly YfsIntentRouter $intentRouter,
        private readonly JfsReadService $jfs,
        private readonly BotOutcomeService $outcomes,
        private readonly OperatorHandoffRecoveryService $handoffRecovery,
        private readonly ParticipationLocationService $location,
        private readonly EventDateGuard $eventDates,
        private readonly OutboundLinkThrottle $linkThrottle,
    ) {}

    /**
     * @param  array{
     *     sender_id: string,
     *     message_id: string,
     *     text: string,
     *     timestamp?: int|string|null,
     *     attachments?: list<array{type?: string, url?: string, mime?: string|null}>,
     *     auto_reply?: bool,
     *     resolve_media?: bool
     * }  $payload
     */
    public function handle(array $payload): void
    {
        $account = InstagramAccount::primary();
        if (! $account->isConnected()) {
            return;
        }

        $senderId = trim((string) ($payload['sender_id'] ?? ''));
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        $text = trim((string) ($payload['text'] ?? ''));
        $attachments = $this->normalizeIncomingAttachments($payload['attachments'] ?? null);
        $autoReply = array_key_exists('auto_reply', $payload)
            ? (bool) $payload['auto_reply']
            : true;
        $resolveMedia = (bool) ($payload['resolve_media'] ?? false);

        if ($senderId === '' || $messageId === '') {
            return;
        }

        if (ConversationMessage::query()->where('external_id', $messageId)->exists()) {
            return;
        }

        if ($attachments === [] && ($resolveMedia || $text === '')) {
            $attachments = $this->mediaResolver->lookupImageAttachments(
                $messageId,
                (string) $account->access_token_encrypted,
            );
        }

        if ($text === '' && $attachments === [] && ! $resolveMedia) {
            Log::info('Instagram inbound skipped: no text and no image attachment.', [
                'sender_id' => $senderId,
                'message_id' => $messageId,
            ]);

            return;
        }

        $sentAt = $this->resolveTimestamp($payload['timestamp'] ?? null);

        $conversation = Conversation::query()->firstOrCreate(
            [
                'channel' => 'instagram',
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
                Log::warning('Instagram attachment download failed.', [
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
            'body' => $text !== '' ? $text : ($attachmentPath ? '[Instagram image attached]' : '[Instagram media attached]'),
            'attachment_path' => $attachmentPath,
            'attachment_mime' => $attachmentMime,
            'sent_at' => $sentAt,
        ]);

        Cache::lock('instagram-conversation-'.$conversation->id, 180)->block(120, function () use (
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
            $awaitingOperatorOffer = $this->handoffClassifier->isAwaitingOperatorOffer(
                $conversation->bot_awaiting_topic,
            );

            $conversationUpdates = [
                'last_message_at' => ($conversation->last_message_at === null || $sentAt->gte($conversation->last_message_at))
                    ? $sentAt
                    : $conversation->last_message_at,
                'status' => $conversation->status === Conversation::STATUS_CLOSED
                    ? Conversation::STATUS_OPEN
                    : $conversation->status,
                'bot_awaits_reply' => false,
                'bot_reminder_sent_at' => null,
            ];
            if ($autoReply) {
                $conversationUpdates['bot_awaiting_topic'] = null;
                $conversationUpdates['bot_awaiting_since'] = null;
            }
            $conversation->forceFill($conversationUpdates)->save();

            if (! $autoReply) {
                $this->decisionTraces->recordSkipped($conversation, $inboundMessage, $text, 'auto_reply_disabled_for_ingest');
                return;
            }

            if ($this->hasOutboundAfterInbound($conversation, $inboundMessage)) {
                $this->decisionTraces->recordSkipped($conversation, $inboundMessage, $text, 'outbound_already_exists');
                Log::info('Instagram auto-reply skipped: outbound already exists after inbound.', [
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
                    $awaitingOperatorOffer,
                    waitForBurst: true,
                );
                $this->retryTracker->clear($inboundMessage);
            } catch (Throwable $exception) {
                BotDecisionTraceService::failed($exception);
                Log::warning('Instagram bot auto-reply failed.', [
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

    public function retryLatestUnansweredInbound(Conversation $conversation, bool $force = false, bool $nudge = false): bool
    {
        $account = InstagramAccount::primary();
        if (! $account->isConnected()) {
            return false;
        }

        $lock = Cache::lock('instagram-conversation-'.$conversation->id, 180);
        if (! $lock->get()) {
            return false;
        }

        try {
            return $this->retryLatestUnansweredInboundLocked($account, $conversation, $force, $nudge);
        } finally {
            $lock->release();
        }
    }

    private function retryLatestUnansweredInboundLocked(
        InstagramAccount $account,
        Conversation $conversation,
        bool $force = false,
        bool $nudge = false,
    ): bool {
        $conversation->refresh();

        if (! $this->shouldAutoReply($conversation, $account)) {
            return false;
        }

        $inboundQuery = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        $inboundMessage = $nudge
            ? (clone $inboundQuery)->where('direction', ConversationMessage::DIRECTION_INBOUND)->first()
            : $inboundQuery->first();

        if ($inboundMessage === null || $inboundMessage->direction !== ConversationMessage::DIRECTION_INBOUND) {
            return false;
        }

        $sentAt = $this->retryTracker->sentAt($inboundMessage);

        if ($this->hasOutboundAfterInbound($conversation, $inboundMessage) && ! $nudge) {
            $this->retryTracker->clear($inboundMessage);

            return false;
        }

        if (! $force && ! $nudge && ! $this->retryTracker->canRetry($inboundMessage)) {
            return false;
        }

        $text = $this->inboundBodyForReply($inboundMessage);
        $awaitingOperatorOffer = $this->handoffClassifier->isAwaitingOperatorOffer(
            $conversation->bot_awaiting_topic,
        );

        $outboundBefore = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->count();

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
                $awaitingOperatorOffer,
                waitForBurst: false,
                ignoreExistingOutbound: $nudge,
            );
            $this->retryTracker->clear($inboundMessage);

            $outboundAfter = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
                ->count();

            return $outboundAfter > $outboundBefore;
        } catch (Throwable $exception) {
            BotDecisionTraceService::failed($exception);
            Log::warning('Instagram bot auto-reply retry failed.', [
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
        InstagramAccount $account,
        Conversation $conversation,
        string $senderId,
        ConversationMessage $inboundMessage,
        string $text,
        ?string $attachmentPath,
        ?string $attachmentMime,
        bool $attachmentDownloadFailed,
        Carbon $sentAt,
        bool $awaitingOperatorOffer,
        bool $waitForBurst,
        bool $ignoreExistingOutbound = false,
    ): void {
        $conversation->refresh();

        if ($this->handoffRecovery->recoverConversation($conversation)) {
            BotDecisionTraceService::finishIfRunning('skipped', 'operator_handoff_recovered');

            return;
        }

        if ($waitForBurst && ! $this->shouldReplyAsBatchLeader($conversation, $inboundMessage)) {
            Log::info('Instagram auto-reply deferred: newer inbound in burst.', [
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

        if ($this->hasOutboundAfterInbound($conversation, $inboundMessage) && ! $ignoreExistingOutbound) {
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

        if ($this->handleYfsOperatorAndReply(
            $account,
            $conversation,
            $senderId,
            $effectiveText,
            $awaitingOperatorOffer,
        )) {
            return;
        }

        $route = $this->intentRouter->route($effectiveText);
        $factBlocks = [];
        $lookup = null;
        $loadShowBrands = ($route['wants_brands'] ?? false) || $this->customerAsksForShowBrands($effectiveText);
        $formConfig = BotSetting::instance()->prompt_config ?? [];
        $formConfig = is_array($formConfig) ? $formConfig : [];
        $this->location->rememberFromCustomer($conversation, $effectiveText);

        if ($loadShowBrands) {
            $route['topics'] = array_values(array_unique(array_merge(
                array_values(array_diff($route['topics'], ['designers'])),
                ['show_brands', 'events'],
            )));
        }

        $events = $this->jfs->publicEvents();

        if ($route['wants_events'] || $loadShowBrands) {
            $factBlocks[] = $this->jfs->eventsFactBlock($events);
        }

        if ($loadShowBrands) {
            $factBlocks[] = $this->jfs->brandsFactBlock($this->jfs->publicBrandLineups());
        }

        if ($route['email'] !== null) {
            $lookup = $this->jfs->findClientByEmail($route['email']);
            $factBlocks[] = $this->jfs->clientFactBlock($lookup);
        }

        if ($route['action'] === YfsIntentRouter::ACTION_HANDOFF) {
            $this->transferToOperator($account, $conversation, $senderId, $effectiveText, $lookup, $route['email']);

            return;
        }

        $replyText = $this->aiReplyGenerator->generate(
            $this->contextBuilder->buildRoutedSystemPrompt($conversation, $effectiveText, $route['topics'], $factBlocks),
            $this->contextBuilder->buildUserPrompt($conversation, $effectiveText),
            trace: [
                'conversation_id' => $conversation->id,
                'purpose' => 'reply_generate',
            ],
        );

        if ($this->staleBecauseNewerInbound($conversation, $inboundMessage, 'Instagram')) {
            return;
        }

        if ($this->handoffClassifier->isHandoffClaimText($replyText)) {
            $this->completeOperatorHandoff(
                $account,
                $conversation,
                $senderId,
                $effectiveText,
                $lookup,
                $route['email'] ?? null,
                $replyText,
            );

            return;
        }

        $asksOperator = $this->handoffClassifier->isOperatorOfferText($replyText);
        $replyText = $this->location->gateReply($conversation->fresh(), $replyText, $formConfig, $effectiveText);
        $replyText = $this->eventDates->sanitize($replyText, $conversation, $effectiveText, $events);
        $this->sendBotReply(
            $account,
            $conversation,
            $senderId,
            $replyText,
            awaitsReply: true,
            awaitingTopic: $asksOperator ? HumanHandoffClassifier::TOPIC_OPERATOR_OFFER : null,
            customerText: $effectiveText,
        );

        $this->recordYfsReplyOutcomes($conversation, $effectiveText, $replyText, $lookup, $route);
    }

    /**
     * @param  array{status:string,client:?array,children:list<array>}|null  $lookup
     */
    private function handleYfsOperatorAndReply(
        InstagramAccount $account,
        Conversation $conversation,
        string $senderId,
        string $effectiveText,
        bool $awaitingOperatorOffer,
    ): bool {
        $offeredOperator = $awaitingOperatorOffer || $this->lastOutboundOfferedOperator($conversation);

        if ($offeredOperator && $this->handoffClassifier->isHandoffAffirmative($effectiveText)) {
            $this->transferToOperator($account, $conversation, $senderId, $effectiveText, null, null);

            return true;
        }

        if ($awaitingOperatorOffer && $this->handoffClassifier->isHandoffNegative($effectiveText)) {
            return false;
        }

        return false;
    }

    /**
     * @param  array{status:string,client:?array,children:list<array>}|null  $lookup
     */
    private function transferToOperator(
        InstagramAccount $account,
        Conversation $conversation,
        string $senderId,
        string $latestMessage,
        ?array $lookup,
        ?string $email,
    ): void {
        $this->completeOperatorHandoff(
            $account,
            $conversation,
            $senderId,
            $latestMessage,
            $lookup,
            $email,
        );
    }

    /**
     * @param  array{status:string,client:?array,children:list<array>}|null  $lookup
     */
    private function completeOperatorHandoff(
        InstagramAccount $account,
        Conversation $conversation,
        string $senderId,
        string $latestMessage,
        ?array $lookup,
        ?string $email,
        ?string $alreadyComposedReply = null,
    ): void {
        $this->sendBotReply(
            $account,
            $conversation,
            $senderId,
            $alreadyComposedReply ?? $this->humanHandoffReply($conversation, $latestMessage),
            awaitsReply: false,
        );
        $this->moveToHuman($conversation);
        $this->outcomes->record(
            $conversation,
            BotReply::TYPE_OPERATOR_NEEDED,
            'User needs a live operator. / Пользователю требуется оператор.',
            [
                'email' => $email,
                'question' => $latestMessage,
                'client' => $lookup['client'] ?? null,
                'children' => $lookup['children'] ?? [],
                'lookup_status' => $lookup['status'] ?? null,
            ],
        );
    }

    private function lastOutboundOfferedOperator(Conversation $conversation): bool
    {
        $lastOutbound = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->orderByDesc('id')
            ->first();

        return $lastOutbound !== null
            && $this->handoffClassifier->isOperatorOfferText((string) $lastOutbound->body);
    }

    /**
     * @param  array{status:string,client:?array,children:list<array>}|null  $lookup
     * @param  array{action:string,topics:list<string>,email:?string,wants_events:bool,wants_lookup:bool,wants_brands?:bool}  $route
     */
    private function recordYfsReplyOutcomes(
        Conversation $conversation,
        string $customerText,
        string $replyText,
        ?array $lookup,
        array $route,
    ): void {
        $formUrl = $this->detectFormUrl($replyText);
        if ($formUrl !== null) {
            $this->outcomes->record(
                $conversation,
                BotReply::TYPE_FORM_SENT,
                'Bot sent an application form link.',
                ['form_url' => $formUrl, 'question' => $customerText],
            );
        }

        if ($lookup !== null) {
            $found = ($lookup['status'] ?? '') === 'found';
            $this->outcomes->record(
                $conversation,
                $found ? BotReply::TYPE_CLIENT_FOUND : BotReply::TYPE_CLIENT_NOT_FOUND,
                $found ? 'Client found in JFS. Request passed to manager.' : 'Client not found in JFS. Request passed to manager.',
                [
                    'email' => $route['email'],
                    'question' => $customerText,
                    'client' => $lookup['client'] ?? null,
                    'children' => $lookup['children'] ?? [],
                    'lookup_status' => $lookup['status'] ?? null,
                ],
            );

            return;
        }

        $managerTopics = array_intersect($route['topics'] ?? [], ['complaints', 'client_lookup']);
        if ($managerTopics !== [] || ($route['wants_lookup'] ?? false)) {
            $this->outcomes->record(
                $conversation,
                BotReply::TYPE_MANAGER_REQUEST,
                'Case accepted and passed to the manager.',
                [
                    'question' => $customerText,
                    'topics' => array_values($managerTopics),
                ],
            );
        }
    }

    private function customerAsksForShowBrands(string $text): bool
    {
        $lower = mb_strtolower($text);
        $mentionsBrands = str_contains($lower, 'бренд')
            || str_contains($lower, 'brand')
            || str_contains($lower, 'дизайнер')
            || str_contains($lower, 'designer');

        if (! $mentionsBrands) {
            return false;
        }

        return str_contains($lower, 'как')
            || str_contains($lower, 'which')
            || str_contains($lower, 'what')
            || str_contains($lower, 'назван')
            || str_contains($lower, 'прошл')
            || str_contains($lower, 'список')
            || str_contains($lower, 'будут')
            || str_contains($lower, 'were')
            || str_contains($lower, 'past');
    }

    private function detectFormUrl(string $reply): ?string
    {
        $config = BotSetting::instance()->prompt_config ?? [];
        $urls = InstagramOutboundFormButtons::urlsIn($reply, is_array($config) ? $config : []);

        return $urls[0] ?? null;
    }

    private function inboundBodyForReply(ConversationMessage $message): string
    {
        $text = trim((string) $message->body);

        if ($text === '[Instagram image attached]') {
            return '';
        }

        return $text;
    }

    private function shouldAutoReply(Conversation $conversation, InstagramAccount $account): bool
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
        InstagramAccount $account,
        Conversation $conversation,
        string $senderId,
        string $replyText,
        bool $awaitsReply = false,
        ?string $awaitingTopic = null,
        string $customerText = '',
    ): void {
        $formConfig = BotSetting::instance()->prompt_config ?? [];
        $locale = $this->languageResolver->resolveFromConversation($conversation);
        $parts = $this->messageSender->sendReplyWithFormButtons(
            $account,
            $senderId,
            $replyText,
            $locale,
            false,
            is_array($formConfig) ? $formConfig : [],
            $this->linkThrottle->omittedKinds($conversation, $customerText),
        );
        $sentAt = Carbon::now();
        $savedMessageIds = ConversationMessage::saveBotOutbound($conversation->id, $parts, $sentAt);

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

    public function hydrateParticipantProfile(Conversation $conversation, InstagramAccount $account, string $senderId): void
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
            $profile = $this->metaOAuthService->getInstagramUserProfile($token, $senderId);

            if ($this->profileBelongsToAccount($account, $profile, $senderId)) {
                return;
            }

            $conversation->forceFill([
                'participant_username' => $profile['username'] ?? $conversation->participant_username,
                'participant_name' => $profile['name'] ?? $conversation->participant_name,
            ])->save();

            $this->contextBuilder->linkCustomer($conversation->fresh());
        } catch (\RuntimeException) {
            // Profile lookup is best-effort for customer IGSID.
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function profileBelongsToAccount(InstagramAccount $account, array $profile, string $senderId): bool
    {
        $profileId = trim((string) ($profile['id'] ?? ''));
        $profileUsername = strtolower(ltrim((string) ($profile['username'] ?? ''), '@'));
        $accountUsername = strtolower(ltrim((string) data_get($account->settings, 'instagram_username', $account->name), '@'));

        if ($account->ownsInstagramId($profileId) || $account->ownsInstagramId($senderId)) {
            return true;
        }

        return $accountUsername !== '' && $profileUsername === $accountUsername;
    }

    /**
     * @return list<array{type: string, url: string, mime: string|null}>
     */
    private function normalizeIncomingAttachments(mixed $attachments): array
    {
        if (! is_array($attachments) || $attachments === []) {
            return [];
        }

        return $this->mediaResolver->extractImageAttachments([
            'attachments' => $attachments,
        ]);
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
