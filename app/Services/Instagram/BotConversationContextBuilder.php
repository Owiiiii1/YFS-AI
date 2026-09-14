<?php

namespace App\Services\Instagram;

use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Bot\BotPromptAssembler;
use RuntimeException;

class BotConversationContextBuilder
{
    private const MAX_HISTORY_MESSAGES = 120;

    public function __construct(
        private readonly BotPromptAssembler $promptAssembler,
    ) {}

    public function buildSystemPrompt(Conversation $conversation, string $latestCustomerMessage = ''): string
    {
        $bot = BotSetting::instance();

        if (! $this->promptAssembler->isActive($bot)) {
            throw new RuntimeException('Structured bot prompt configuration is not active.');
        }

        return $this->promptAssembler->buildSystemPrompt($conversation, $latestCustomerMessage, $bot);
    }

    /**
     * @param  list<string>  $extraTopicIds
     * @param  list<string>  $factBlocks
     */
    public function buildRoutedSystemPrompt(
        Conversation $conversation,
        string $latestCustomerMessage,
        array $extraTopicIds = [],
        array $factBlocks = [],
    ): string {
        $bot = BotSetting::instance();

        if (! $this->promptAssembler->isActive($bot)) {
            throw new RuntimeException('Structured bot prompt configuration is not active.');
        }

        return $this->promptAssembler->buildSystemPrompt(
            $conversation,
            $latestCustomerMessage,
            $bot,
            $extraTopicIds,
            $factBlocks,
        );
    }

    public function buildUserPrompt(
        Conversation $conversation,
        string $latestCustomerMessage,
    ): string {
        $sections = [];

        $profile = $this->buildCustomerProfileSection($conversation);
        if ($profile !== '') {
            $sections[] = $profile;
        }

        $history = $this->buildMessageHistorySection($conversation);
        if ($history !== '') {
            $sections[] = $history;
        }

        $sections[] = 'Latest customer message(s) — if multiple lines, they arrived as one burst; answer all of them in this reply:'
            ."\n".trim($latestCustomerMessage);

        return implode("\n\n", $sections);
    }

    public function linkCustomer(Conversation $conversation): void
    {
        if ($conversation->customer_id !== null) {
            return;
        }

        $customer = $this->resolveCustomer($conversation);
        if ($customer === null) {
            return;
        }

        $conversation->forceFill(['customer_id' => $customer->id])->save();
    }

    private function resolveCustomer(Conversation $conversation): ?Customer
    {
        if ($conversation->customer_id !== null) {
            $linked = Customer::query()->find($conversation->customer_id);
            if ($linked !== null) {
                return $linked;
            }
        }

        $participantId = trim((string) $conversation->participant_id);
        $username = $this->normalizeUsername((string) $conversation->participant_username);

        $query = Customer::query();

        if ($participantId !== '') {
            if ($conversation->channel === 'facebook') {
                $byId = (clone $query)->where('facebook_psid', $participantId)->first();
                if ($byId !== null) {
                    return $byId;
                }
            } else {
                $byId = (clone $query)->where('instagram_user_id', $participantId)->first();
                if ($byId !== null) {
                    return $byId;
                }
            }
        }

        if ($username !== '' && $conversation->channel !== 'facebook') {
            $byUsername = Customer::query()
                ->where(function ($query) use ($username): void {
                    $query->whereRaw('LOWER(instagram_username) = ?', [$username])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%@'.$username.'%']);
                })
                ->first();

            if ($byUsername !== null) {
                return $byUsername;
            }
        }

        return null;
    }

    private function buildCustomerProfileSection(Conversation $conversation): string
    {
        $customer = $this->resolveCustomer($conversation);
        if ($customer === null) {
            $channelLabel = $conversation->channel === 'facebook' ? 'Facebook' : 'Instagram';

            return "Customer profile:\nNo linked CRM customer yet. {$channelLabel}: ".$conversation->displayName();
        }

        $lines = [
            'Customer profile (CRM):',
            'Name: '.($customer->name ?: '—'),
            'Phone: '.($customer->phone ?: '—'),
            'Email: '.($customer->email ?: '—'),
            'Address: '.($customer->address ?: '—'),
            'Status: '.($customer->status ?: '—'),
        ];

        if (filled($customer->instagram_username)) {
            $lines[] = 'Instagram: @'.ltrim((string) $customer->instagram_username, '@');
        }

        if (filled($customer->facebook_psid)) {
            $lines[] = 'Facebook PSID: '.$customer->facebook_psid;
        }

        if (filled($customer->notes)) {
            $lines[] = 'Notes: '.trim((string) $customer->notes);
        }

        if ($conversation->channel === 'facebook') {
            $lines[] = 'Facebook chat: '.$conversation->displayName();
        } elseif (filled($conversation->participant_username) && blank($customer->instagram_username)) {
            $lines[] = 'Instagram chat username: @'.ltrim((string) $conversation->participant_username, '@');
        }

        return implode("\n", $lines);
    }

    private function buildMessageHistorySection(Conversation $conversation): string
    {
        $total = $conversation->messages()->count();
        $query = $conversation->messages()->orderBy('sent_at')->orderBy('id');

        if ($total > self::MAX_HISTORY_MESSAGES) {
            $skipped = $total - self::MAX_HISTORY_MESSAGES;
            $messages = $query
                ->skip($skipped)
                ->take(self::MAX_HISTORY_MESSAGES)
                ->get();

            $header = "Chat transcript (showing last {$messages->count()} of {$total} messages):";
        } else {
            $messages = $query->get();
            $header = $total > 0
                ? "Chat transcript ({$total} messages):"
                : 'Chat transcript:';
        }

        if ($messages->isEmpty()) {
            return $header."\n(no previous messages)";
        }

        $lines = [$header];

        foreach ($messages as $message) {
            $body = trim((string) ($message->body ?? ''));
            if ($body === '') {
                $body = '[attachment or empty message]';
            }

            $lines[] = $message->aiContextLabel().': '.$body;
        }

        return implode("\n", $lines);
    }

    private function normalizeUsername(string $username): string
    {
        return strtolower(ltrim(trim($username), '@'));
    }
}
