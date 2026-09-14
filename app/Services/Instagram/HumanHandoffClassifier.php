<?php

namespace App\Services\Instagram;

class HumanHandoffClassifier
{
    public const TOPIC_OPERATOR_OFFER = 'operator_offer';

    public const TOPIC_OPERATOR = 'operator';

    public function isAwaitingOperatorOffer(?string $topic): bool
    {
        return in_array($topic, [self::TOPIC_OPERATOR_OFFER, self::TOPIC_OPERATOR], true);
    }

    public function isHandoffAffirmative(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(no|nope|nah|not now|dont|нет|ні|не надо|не треба|just asking)\b/ui', $normalized)
            || str_contains(mb_strtolower($message), "don't")) {
            return false;
        }

        if (preg_match('/\b(switch|transfer|connect|менеджер\w*|переключ\w*)\b/ui', $message)) {
            return true;
        }

        if (in_array($normalized, [
            'yes', 'y', 'yeah', 'yep', 'ok', 'okay', 'sure', 'please', 'please do',
            'да', 'ага', 'угу', 'ок', 'окей', 'давай', 'хорошо', 'ладно',
            'так', 'добре', 'гаразд', 'будь ласка',
        ], true)) {
            return true;
        }

        return mb_strlen($normalized) <= 24 && preg_match(
            '/^(yes|yeah|yep|ok|okay|sure|please|да|так|ок|окей|давай)(\s+(please|пожалуйста|будь ласка))?$/ui',
            $normalized,
        );
    }

    public function isHandoffNegative(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        if ($normalized === '' || mb_strlen($normalized) > 32) {
            return false;
        }

        return (bool) preg_match(
            '/^(no|nope|nah|no thanks|no thank you|not now|dont|нет|ні|не надо|не треба|не хочу|неа)(\s+(thanks|thank you|спасибо|дякую))?$/ui',
            $normalized,
        );
    }

    public function isHandoffClaimText(string $reply): bool
    {
        $lower = mb_strtolower($reply);

        return str_contains($lower, "i'm connecting you")
            || str_contains($lower, 'im connecting you')
            || str_contains($lower, 'connecting you with our operator')
            || str_contains($lower, 'подключаю оператора')
            || str_contains($lower, 'підключаю оператора')
            || str_contains($lower, 'please wait for their reply')
            || str_contains($lower, 'ожидайте ответа')
            || str_contains($lower, 'очікуйте відповіді')
            || str_contains($lower, 'скоро оператор')
            || str_contains($lower, 'оператор подключится')
            || str_contains($lower, 'оператор підключить')
            || str_contains($lower, 'an operator will contact')
            || str_contains($lower, 'operator will contact');
    }

    public function isOperatorOfferText(string $reply): bool
    {
        if ($this->isHandoffClaimText($reply)) {
            return false;
        }

        $lower = mb_strtolower($reply);

        return (bool) preg_match(
            '/(live operator|connect you with|connect (a |the )?manager|подключить вас к|подключу (вас )?(к )?(живому )?(оператор|менеджер)|хотите.{0,40}подключ|підключити вас до живого|живого оператор|живого менеджер|к живому оператор|к менеджеру|нужен оператор|потрібен оператор)/u',
            $lower,
        ) || str_contains($lower, 'живому оператор')
            || str_contains($lower, 'живого оператор');
    }
}
