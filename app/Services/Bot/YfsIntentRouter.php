<?php

namespace App\Services\Bot;

class YfsIntentRouter
{
    public const ACTION_REPLY = 'reply';

    public const ACTION_OFFER_OPERATOR = 'offer_operator';

    public const ACTION_HANDOFF = 'handoff';

    /**
     * @return array{action:string,topics:list<string>,email:?string,wants_events:bool,wants_lookup:bool,wants_brands:bool}
     */
    public function route(string $message): array
    {
        $text = trim($message);
        $lower = mb_strtolower($text);
        $topics = [];

        if ($this->explicitOperatorRequest($lower)) {
            return [
                'action' => self::ACTION_HANDOFF,
                'topics' => ['operator'],
                'email' => $this->extractEmail($text),
                'wants_events' => false,
                'wants_lookup' => false,
                'wants_brands' => false,
            ];
        }

        $email = $this->extractEmail($text);
        $wantsLookup = $email !== null || $this->matches($lower, [
            'already applied', 'i applied', 'уже пода', 'вже пода', 'не перезвон', 'не зателефонув',
            'nobody contact', 'no one contact', 'никто не', 'ніхто не', 'оставил заявк', 'залишив заявк',
        ]);

        $wantsEvents = $this->matches($lower, [
            'event', 'ивент', 'івент', 'chicago', 'new york', 'nyc', 'when', 'когда', 'коли',
            'date', 'дат', 'where is the show', 'где шоу', 'де шоу', 'venue', 'локац',
            'шоу', 'show', 'показ', 'график', 'графік', 'расписан', 'розклад', 'schedule',
            'чикаго', 'нью-йорк', 'нью йорк', 'майами', 'маямі', 'miami', 'los angeles',
            'лос-андж', 'лос андж', 'city', 'город', 'місто',
        ]);

        $wantsBrands = $this->matches($lower, [
            'какие бренд', 'какой бренд', 'каких бренд', 'какие дизайнер',
            'which brand', 'what brand', 'which designer', 'what designer',
            'brand names', 'названия бренд', 'список бренд', 'список дизайнер',
            'прошлых шоу', 'прошлых ивент', 'прошлых показ', 'на прошл',
            'previous show', 'past show', 'past event', 'previous event',
            'будут показывать', 'who is showing', 'чьи коллекции',
            'дизайнеры на', 'бренды на', 'бренди на',
        ]);

        $isDesignerLead = $this->matches($lower, [
            "i'm a designer", 'i am a designer', 'we are a designer',
            'my brand', 'our brand', 'у нас бренд', 'мой бренд', 'наш бренд',
            'kids clothing brand', 'хочу сотрудничать',
            'дети показывали одежду', 'want children to wear',
            'хочу чтобы дети', 'brand partnership',
        ]);

        if (! $wantsBrands && ! $isDesignerLead && $this->matches($lower, ['бренд', 'brand', 'дизайнер', 'designer'])) {
            $wantsBrands = true;
        }

        if ($isDesignerLead) {
            $topics[] = 'designers';
        } elseif ($wantsBrands) {
            $topics[] = 'show_brands';
        } elseif ($this->matches($lower, [
            'makeup', 'визаж', 'photographer', 'фотограф', 'videographer', 'стилист', 'stylist',
            'backstage', 'sponsor', 'partner', 'партнер', 'партнёр', 'хочу работа', 'хочу працюв',
        ])) {
            $topics[] = 'team_partners';
        }

        if ($this->matches($lower, [
            'refund', 'возврат', 'скарг', 'complaint', 'cancel', 'отмен', 'не получил фото',
            'не отримав фото', 'payment issue', 'оплатил', 'оплатив',
        ])) {
            $topics[] = 'complaints';
        }

        if ($this->matches($lower, ['scam', 'развод', 'афера', 'agency', 'агентств', 'contract', 'контракт', 'think'])) {
            $topics[] = 'objections';
        }

        if ($wantsEvents) {
            $topics[] = 'events';
        }

        if ($wantsLookup) {
            $topics[] = 'client_lookup';
        }

        if ($topics === [] || $this->matches($lower, [
            'info', 'tell me', 'participate', 'участ', 'дочь', 'дочк', 'сын', 'син', 'child',
            'how much', 'сколько', 'скільки', 'price', 'цена', 'experience', 'опыт', 'хлопчик', 'boy',
        ])) {
            $topics[] = 'parents';
        }

        $topics = array_values(array_unique($topics));

        return [
            'action' => self::ACTION_REPLY,
            'topics' => $topics,
            'email' => $email,
            'wants_events' => $wantsEvents,
            'wants_lookup' => $wantsLookup,
            'wants_brands' => $wantsBrands && ! $isDesignerLead,
        ];
    }

    public function extractEmail(string $text): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $match) !== 1) {
            return null;
        }

        $email = mb_strtolower($match[0]);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function explicitOperatorRequest(string $lower): bool
    {
        return $this->matches($lower, [
            'live operator', 'real person', 'human please', 'talk to a person', 'talk to someone',
            'speak to a manager', 'speak to manager', 'connect me', 'transfer me',
            'живой оператор', 'живого оператор', 'переключи', 'позови менеджер', 'позови оператор',
            'хочу оператор', 'хочу менеджер', 'потрібен оператор', 'потрібен менеджер',
            'менеджера пожалуйста', 'оператора пожалуйста',
        ]);
    }

    /**
     * @param  list<string>  $needles
     */
    private function matches(string $lower, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
