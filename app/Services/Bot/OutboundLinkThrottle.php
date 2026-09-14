<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Support\InstagramOutboundLinkButtons;

/**
 * The same YouTube / website / Instagram link should be sent once per conversation.
 * Repeating it in every reply looks like spam. The application form is never throttled.
 */
class OutboundLinkThrottle
{
    private const THROTTLED_KINDS = ['youtube', 'website', 'instagram'];

    /**
     * @return list<string> link kinds that must not be sent again in this reply
     */
    public function omittedKinds(Conversation $conversation, string $customerText = ''): array
    {
        if (! $conversation->exists || $this->customerAsksForLink($customerText)) {
            return [];
        }

        $sent = [];
        $bodies = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->orderByDesc('id')
            ->limit(60)
            ->pluck('body');

        foreach ($bodies as $body) {
            foreach (InstagramOutboundLinkButtons::httpsUrlsIn((string) $body) as $url) {
                $kind = InstagramOutboundLinkButtons::kind($url);
                if (in_array($kind, self::THROTTLED_KINDS, true) && ! in_array($kind, $sent, true)) {
                    $sent[] = $kind;
                }
            }
        }

        return $sent;
    }

    private function customerAsksForLink(string $text): bool
    {
        $lower = mb_strtolower($text);
        if ($lower === '') {
            return false;
        }

        foreach ([
            'ссылк', 'посилан', 'link', 'сайт', 'website', 'youtube', 'ютуб',
            'где посмотреть', 'де подивитися', 'where can i see', 'where to watch',
            'скинь', 'скиньте', 'надішліть', 'покажи',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
