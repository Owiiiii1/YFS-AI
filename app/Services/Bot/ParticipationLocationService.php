<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Instagram\ConversationLanguageResolver;
use App\Support\BotFormLinks;
use App\Support\InstagramOutboundFormButtons;
use App\Support\InstagramOutboundLinkButtons;

class ParticipationLocationService
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_US = 'us';

    public const STATUS_OUTSIDE_US = 'outside_us';

    public const STATUS_WILL_TRAVEL = 'will_travel';

    public const STATUS_NOT_COMING = 'not_coming';

    public const PROMPTED_STATE = 'state';

    public const PROMPTED_TRAVEL = 'travel';

    public const PROMPTED_AGE = 'age';

    public const PROMPTED_FAMILY_LOOK = 'family_look';

    public const AGE_UNKNOWN = 'unknown';

    /** Child is 5+: may walk solo or with a parent. */
    public const AGE_SOLO = 'solo';

    /** Child is 3 to 5: only possible as Family Look with a parent. */
    public const AGE_FAMILY_LOOK = 'family_look';

    /** Family Look was explained and the family accepted it. */
    public const AGE_FAMILY_LOOK_OK = 'family_look_ok';

    /** Family Look was explained and the family declined it. */
    public const AGE_FAMILY_LOOK_DECLINED = 'family_look_declined';

    /** Child is under 3: we do not take part yet. */
    public const AGE_TOO_YOUNG = 'too_young';

    /** Solo runway starts at this age. */
    private const SOLO_MIN_AGE = 5;

    /** Youngest age we accept at all, and only in Family Look. */
    private const MIN_AGE = 3;

    public function __construct(
        private readonly ConversationLanguageResolver $languageResolver,
    ) {}

    /**
     * @return array{status: string, place: ?string, prompted: ?string, age: ?float, age_status: string}
     */
    public function rememberFromCustomer(Conversation $conversation, string $customerText): array
    {
        $data = is_array($conversation->intake_data) ? $conversation->intake_data : [];
        $current = is_array($data['participation'] ?? null) ? $data['participation'] : [];
        $lastBot = $this->lastBotBody($conversation);

        // The current message decides; older messages may only fill in what is still unknown,
        // never undo an answer the customer already gave.
        $next = $this->detect($customerText, $current, $lastBot);
        if ($next['status'] === self::STATUS_UNKNOWN || $next['age_status'] === self::AGE_UNKNOWN) {
            $fromHistory = $this->detect($this->locationHaystack($conversation, $customerText), $current, $lastBot);
            if ($next['status'] === self::STATUS_UNKNOWN && $fromHistory['status'] !== self::STATUS_UNKNOWN) {
                $next['status'] = $fromHistory['status'];
                $next['place'] = $fromHistory['place'];
                $next['prompted'] = $fromHistory['prompted'];
            }
            if ($next['age_status'] === self::AGE_UNKNOWN && $fromHistory['age_status'] !== self::AGE_UNKNOWN) {
                $next['age'] = $fromHistory['age'];
                $next['age_status'] = $fromHistory['age_status'];
            }
        }

        $data['participation'] = $next;
        $conversation->forceFill(['intake_data' => $data])->save();

        return $next;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{status: string, place: ?string, prompted: ?string, age: ?float, age_status: string}
     */
    public function detect(string $customerText, array $current = [], ?string $lastBotText = null): array
    {
        $location = $this->detectLocation($customerText, $current, $lastBotText);
        $age = $this->detectAge($customerText, $current, $lastBotText);

        return array_merge($location, $age);
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{status: string, place: ?string, prompted: ?string}
     */
    private function detectLocation(string $customerText, array $current = [], ?string $lastBotText = null): array
    {
        $status = (string) ($current['status'] ?? self::STATUS_UNKNOWN);
        $place = isset($current['place']) ? (string) $current['place'] : null;
        $prompted = isset($current['prompted']) ? (string) $current['prompted'] : null;
        if ($prompted === '') {
            $prompted = null;
        }

        $inferredPrompt = $this->inferPrompted($lastBotText);
        if ($inferredPrompt !== null) {
            $prompted = $inferredPrompt;
        }

        $usPlace = $this->matchUsPlace($customerText);
        $foreignPlace = $this->matchForeignPlace($customerText);

        if ($usPlace !== null) {
            if ($foreignPlace !== null
                || $status === self::STATUS_OUTSIDE_US
                || $prompted === self::PROMPTED_TRAVEL) {
                return [
                    'status' => self::STATUS_WILL_TRAVEL,
                    'place' => trim(implode(' / ', array_filter([$foreignPlace ?? $place, $usPlace]))),
                    'prompted' => null,
                ];
            }

            return [
                'status' => self::STATUS_US,
                'place' => $usPlace,
                'prompted' => null,
            ];
        }

        if ($foreignPlace !== null) {
            // A family travelling to the show naturally names their home country; that must not
            // undo a confirmed answer. Only an explicit "we are not in the USA" does.
            if (in_array($status, [self::STATUS_US, self::STATUS_WILL_TRAVEL], true)
                && ! $this->isExplicitlyOutsideUsa($this->foldForPlaceMatch($customerText))) {
                return [
                    'status' => $status,
                    'place' => $place ?? $foreignPlace,
                    'prompted' => null,
                ];
            }

            return [
                'status' => self::STATUS_OUTSIDE_US,
                'place' => $foreignPlace,
                'prompted' => self::PROMPTED_TRAVEL,
            ];
        }

        if ($status === self::STATUS_NOT_COMING && $this->announcesTravel($customerText)) {
            return [
                'status' => self::STATUS_WILL_TRAVEL,
                'place' => $place,
                'prompted' => null,
            ];
        }

        if (in_array($status, [self::STATUS_US, self::STATUS_WILL_TRAVEL, self::STATUS_NOT_COMING], true)) {
            return [
                'status' => $status,
                'place' => $place,
                'prompted' => null,
            ];
        }

        if ($status === self::STATUS_OUTSIDE_US || $prompted === self::PROMPTED_TRAVEL) {
            if ($this->isNegative($customerText)) {
                return [
                    'status' => self::STATUS_NOT_COMING,
                    'place' => $place,
                    'prompted' => null,
                ];
            }
            if ($this->isAffirmative($customerText)) {
                return [
                    'status' => self::STATUS_WILL_TRAVEL,
                    'place' => $place,
                    'prompted' => null,
                ];
            }
        }

        return [
            'status' => $status !== '' ? $status : self::STATUS_UNKNOWN,
            'place' => $place,
            'prompted' => $prompted,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{age: ?float, age_status: string, age_prompted: bool}
     */
    private function detectAge(string $customerText, array $current = [], ?string $lastBotText = null): array
    {
        $age = isset($current['age']) && is_numeric($current['age']) ? (float) $current['age'] : null;
        $status = (string) ($current['age_status'] ?? self::AGE_UNKNOWN);
        $asked = (bool) ($current['age_prompted'] ?? false) || $this->alreadyAsksAge((string) $lastBotText);
        $offeredFamilyLook = $this->alreadyExplainsFamilyLook((string) $lastBotText);

        $detected = $this->matchAgeYears($customerText, $asked);
        if ($detected !== null) {
            $age = $detected;
            $status = match (true) {
                $detected >= self::SOLO_MIN_AGE => self::AGE_SOLO,
                $detected >= self::MIN_AGE => self::AGE_FAMILY_LOOK,
                default => self::AGE_TOO_YOUNG,
            };
        }

        if ($detected === null
            && in_array($status, [self::AGE_FAMILY_LOOK, self::AGE_FAMILY_LOOK_DECLINED], true)
            && ($offeredFamilyLook || $status === self::AGE_FAMILY_LOOK)) {
            if ($this->isNegative($customerText)) {
                $status = self::AGE_FAMILY_LOOK_DECLINED;
            } elseif ($this->isAffirmative($customerText) || $this->acceptsFormat($customerText)) {
                $status = self::AGE_FAMILY_LOOK_OK;
            }
        }

        return [
            'age' => $age,
            'age_status' => $status,
            'age_prompted' => $asked,
        ];
    }

    /**
     * Location alone is confirmed: they are in the USA or will travel for the show.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function locationAllows(?array $state): bool
    {
        $status = (string) ($state['status'] ?? self::STATUS_UNKNOWN);

        return in_array($status, [self::STATUS_US, self::STATUS_WILL_TRAVEL], true);
    }

    /**
     * Child age fits a real category: 5+ solo, or under 5 with an accepted Family Look.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function ageAllows(?array $state): bool
    {
        return in_array(
            (string) ($state['age_status'] ?? self::AGE_UNKNOWN),
            [self::AGE_SOLO, self::AGE_FAMILY_LOOK_OK],
            true,
        );
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public function allowsParticipantForm(?array $state): bool
    {
        return $this->locationAllows($state) && $this->ageAllows($state);
    }

    public function conversationAllowsParticipantForm(Conversation $conversation): bool
    {
        $data = is_array($conversation->intake_data) ? $conversation->intake_data : [];
        $state = is_array($data['participation'] ?? null) ? $data['participation'] : [];

        return $this->allowsParticipantForm($state);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function factBlock(Conversation $conversation, array $config = []): string
    {
        $official = InstagramOutboundLinkButtons::officialUrls($config);
        $data = is_array($conversation->intake_data) ? $conversation->intake_data : [];
        $state = is_array($data['participation'] ?? null) ? $data['participation'] : [];
        $status = (string) ($state['status'] ?? self::STATUS_UNKNOWN);
        $place = trim((string) ($state['place'] ?? ''));
        $prompted = (string) ($state['prompted'] ?? '');
        $ageStatus = (string) ($state['age_status'] ?? self::AGE_UNKNOWN);
        $age = isset($state['age']) && is_numeric($state['age']) ? (float) $state['age'] : null;

        $lines = [
            'PARTICIPATION QUALIFICATION (authoritative). Order: 1) location, 2) child age, 3) only then the form.',
            'Shows are only in the USA: Los Angeles, Miami, Chicago, New York.',
            'Young Fashion Show never pays travel, flights, visas, hotels, or relocation. Mention this ONLY if the customer asks who pays for travel, flights, hotels, visas, or thinks an SMS/invite is a paid trip. Do not volunteer it.',
            'STEP 1 — location. If unknown: ask which U.S. state they are in. Do not send the application form yet. If they already named a U.S. city or state, never ask again.',
            'If they are not in the USA: say the shows are in LA, Miami, Chicago and New York, and ask them to confirm whether they can come to the USA for the show. Do not add a travel-cost lecture unless they asked.',
            'If they confirm they can come: continue to the age step, and when you finally send the form ask them to note in its comment field that they are able to come to the USA for the event.',
            'If they will not come to the USA: stay warm, keep answering their questions, invite them to follow the shows online. Do not send the participant form.',
            'STEP 2 — child age. After location is confirmed, ask how old the child is. Never ask again once they told you.',
            'Age 5 and older: the child can walk solo or together with a parent — send the form.',
            'Age 3 to 5: participation is possible only in the FAMILY LOOK category, where the child walks the runway together with a parent (or both parents). Explain that warmly and ask whether that format suits them. Send the form only after they say it suits them.',
            'Age under 3: we do not take children that young yet. Do not send the form. Warmly say the runway welcomes children from 3 years old (Family Look with a parent) and from 5 also on their own, that you would love to see their little one when the time comes, and invite them to follow the shows. Never phrase it as a rejection or as them not fitting.',
            'STEP 3 — when you send the form, also invite them to see how our past shows looked, plus parent and partner reviews: in our Instagram feed and highlights (say "in our Instagram" — never paste an Instagram link, this chat is already our Instagram), on our website '.$official['website'].', and on YouTube '.$official['youtube'].' (send this URL).',
            'NEVER tell anyone they do not fit, are not suitable, are too young, or are rejected. Never refuse. Stay polite and keep the conversation going — simply do not send the form yet.',
            'If the customer explicitly asks for the application form, send it even if qualification is incomplete.',
            'An unconfirmed show date or an unchosen city never delays the form: once location and age fit, send it and say the coordinator confirms dates and details afterwards.',
            'Do not paste the website or YouTube link again if it was already sent earlier in this conversation.',
            'Current location status: '.$status,
        ];
        if ($place !== '') {
            $lines[] = 'Known place: '.$place;
        }
        if ($prompted !== '') {
            $lines[] = 'Last location question asked: '.$prompted;
        }
        $lines[] = 'Current age status: '.$ageStatus;
        if ($age !== null) {
            $lines[] = 'Known child age: '.rtrim(rtrim(number_format($age, 1, '.', ''), '0'), '.');
        }
        $lines[] = 'Location confirmed: '.($this->locationAllows($state) ? 'yes' : 'no');
        $lines[] = 'Age category confirmed: '.($this->ageAllows($state) ? 'yes' : 'no');
        $lines[] = 'Participant form allowed now: '.($this->allowsParticipantForm($state) ? 'yes' : 'no');

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function gateReply(
        Conversation $conversation,
        string $replyText,
        array $config = [],
        string $customerText = '',
    ): string {
        $data = is_array($conversation->intake_data) ? $conversation->intake_data : [];
        $state = is_array($data['participation'] ?? null) ? $data['participation'] : [];
        $status = (string) ($state['status'] ?? self::STATUS_UNKNOWN);
        $locale = $this->languageResolver->resolveFromConversation(
            $conversation,
            $customerText !== '' ? $customerText : $replyText,
        );
        $urls = $this->participantUrls($config);
        $hadForm = $this->containsAnyUrl($replyText, $urls);
        $applyIntent = $hadForm || $this->looksLikeApply($customerText);

        if ($this->explicitlyAsksForForm($customerText)) {
            return $this->withTravelCommentNote($replyText, $state, $hadForm, $locale);
        }

        if ($this->allowsParticipantForm($state)) {
            return $this->withTravelCommentNote($replyText, $state, $hadForm, $locale);
        }

        $stripped = $hadForm ? InstagramOutboundFormButtons::strip($replyText, $urls) : $replyText;
        $stripped = trim($stripped);

        if ($this->locationAllows($state)) {
            return $this->gateOnAge($conversation, $stripped, $replyText, $state, $locale, $applyIntent);
        }

        if ($status === self::STATUS_NOT_COMING) {
            $this->storePrompted($conversation, null);
            $closing = $applyIntent
                || $hadForm
                || $this->inferPrompted($this->lastBotBody($conversation)) === self::PROMPTED_TRAVEL;
            if ($closing) {
                return $this->withOnlineLinks($this->copy('not_coming', $locale), $config, $conversation);
            }

            return $stripped !== '' ? $stripped : $replyText;
        }

        if ($status === self::STATUS_OUTSIDE_US && $applyIntent) {
            $this->storePrompted($conversation, self::PROMPTED_TRAVEL);
            if ($this->alreadyAsksTravel($stripped)) {
                return $stripped !== '' ? $stripped : $this->copy('ask_travel', $locale);
            }

            return $this->join($stripped, $this->copy('ask_travel', $locale));
        }

        if ($status === self::STATUS_UNKNOWN && $applyIntent) {
            $this->storePrompted($conversation, self::PROMPTED_STATE);
            if ($this->alreadyAsksState($stripped)) {
                return $stripped !== '' ? $stripped : $this->copy('ask_state', $locale);
            }

            return $this->join($stripped, $this->copy('ask_state', $locale));
        }

        return $stripped !== '' ? $stripped : $replyText;
    }

    /**
     * Location is fine; decide whether the child age still has to be clarified.
     *
     * @param  array<string, mixed>  $state
     */
    private function gateOnAge(
        Conversation $conversation,
        string $stripped,
        string $replyText,
        array $state,
        string $locale,
        bool $applyIntent,
    ): string {
        $ageStatus = (string) ($state['age_status'] ?? self::AGE_UNKNOWN);

        if ($ageStatus === self::AGE_FAMILY_LOOK) {
            $this->storeAgePrompted($conversation, true);
            if ($this->alreadyExplainsFamilyLook($stripped)
                || $this->alreadyExplainsFamilyLook((string) $this->lastBotBody($conversation))) {
                return $stripped !== '' ? $stripped : $this->copy('family_look', $locale);
            }

            return $this->join($stripped, $this->copy('family_look', $locale));
        }

        if ($ageStatus === self::AGE_TOO_YOUNG) {
            if ($this->alreadyExplainsMinAge($stripped)
                || $this->alreadyExplainsMinAge((string) $this->lastBotBody($conversation))) {
                return $stripped !== '' ? $stripped : $this->copy('too_young', $locale);
            }

            return $this->join($stripped, $this->copy('too_young', $locale));
        }

        if ($ageStatus === self::AGE_FAMILY_LOOK_DECLINED) {
            return $stripped !== '' ? $stripped : $replyText;
        }

        if ($applyIntent) {
            $alreadyAsked = $this->alreadyAsksAge($stripped)
                || $this->alreadyAsksAge((string) $this->lastBotBody($conversation));
            $this->storeAgePrompted($conversation, true);
            if ($alreadyAsked) {
                return $stripped !== '' ? $stripped : $this->copy('ask_age', $locale);
            }

            return $this->join($stripped, $this->copy('ask_age', $locale));
        }

        return $stripped !== '' ? $stripped : $replyText;
    }

    /**
     * Families travelling from abroad must flag that in the form's comment field.
     *
     * @param  array<string, mixed>  $state
     */
    private function withTravelCommentNote(string $replyText, array $state, bool $hadForm, string $locale): string
    {
        if (! $hadForm || (string) ($state['status'] ?? '') !== self::STATUS_WILL_TRAVEL) {
            return $replyText;
        }

        $lower = mb_strtolower($replyText);
        foreach (['comment', 'коммент', 'комент'] as $needle) {
            if (str_contains($lower, $needle)) {
                return $replyText;
            }
        }

        return $this->join($replyText, $this->copy('travel_comment', $locale));
    }

    /**
     * The customer asked for the application outright — never refuse that.
     */
    public function customerRequestsForm(string $text): bool
    {
        return $this->explicitlyAsksForForm($text);
    }

    private function explicitlyAsksForForm(string $text): bool
    {
        $lower = mb_strtolower($text);
        if ($lower === '') {
            return false;
        }

        foreach ([
            'send me the form', 'send the form', 'send me the application', 'send application',
            'give me the form', 'give me the link', 'where is the form', 'application link',
            'sign up link', 'i want to apply', 'how do i apply', 'want to fill',
            'скиньте заявк', 'скинь заявк', 'пришлите заявк', 'пришли заявк', 'дайте заявк',
            'отправьте заявк', 'отправь заявк', 'где заявк', 'ссылку на заявк', 'ссылка на заявк',
            'хочу заполнить', 'хочу подать заявк', 'дайте ссылку', 'дайте форму', 'пришлите форму',
            'надішліть заявк', 'надішли заявк', 'скиньте заявку', 'дайте посилання',
            'хочу заповнити', 'хочу подати заявк', 'де заявк',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function alreadyAsksAge(string $text): bool
    {
        $lower = mb_strtolower($text);
        if ($lower === '') {
            return false;
        }

        return (bool) preg_match('/(how old|сколько лет|скільки років|скольько лет|wie alt|ce vârstă|ce varsta|cuántos años|cuantos anos)/u', $lower)
            || (bool) preg_match('/(возраст|вік).{0,20}(ребен|ребён|дит|дочк|сын|син)/u', $lower)
            || (bool) preg_match("/(child|children|kid|daughter|son)('s)?\s+age/u", $lower);
    }

    private function alreadyExplainsMinAge(string $text): bool
    {
        $lower = mb_strtolower($text);

        return (bool) preg_match('/(от 3|від 3|from 3|з 3 рок|3 лет|3 років|ab 3|de 3 ani|desde los 3)/u', $lower);
    }

    private function alreadyExplainsFamilyLook(string $text): bool
    {
        $lower = mb_strtolower($text);

        return str_contains($lower, 'family look')
            || str_contains($lower, 'фемили лук')
            || str_contains($lower, 'фемілі лук')
            || str_contains($lower, 'family-look');
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function matchAgeYears(string $text, bool $asked): ?float
    {
        $lower = mb_strtolower(trim($text));
        if ($lower === '') {
            return null;
        }

        if (preg_match('/(\d{1,2})\s*(month|months|mon\b|мес|місяц|monate)/u', $lower, $m) === 1) {
            return round(((int) $m[1]) / 12, 2);
        }

        $yearUnits = 'year|years|yrs|y\.o|лет|года|годиков|годика|годик|год|роки|рік|років|років|jahre|jahr|ani|años|anos';
        if (preg_match('/(\d{1,2})(?:[.,](\d))?\s*(?:'.$yearUnits.')/u', $lower, $m) === 1) {
            return $this->composeAge($m[1], $m[2] ?? null);
        }

        $subjects = 'ребенку|ребёнку|ребенок|ребёнок|дочке|дочери|дочка|доця|доньці|сыну|сын|сину|дитині|дитина|дівчинці|хлопчику|'
            .'child is|kid is|daughter is|son is|she is|he is|she\'s|he\'s|is now|turns|turned|meine tochter ist|mein sohn ist';
        if (preg_match('/(?:'.$subjects.')\D{0,15}(\d{1,2})(?:[.,](\d))?/u', $lower, $m) === 1) {
            return $this->composeAge($m[1], $m[2] ?? null);
        }

        if ($asked && preg_match('/^\D{0,8}(\d{1,2})(?:[.,](\d))?\D{0,8}$/u', $lower, $m) === 1) {
            $age = $this->composeAge($m[1], $m[2] ?? null);

            return $age !== null && $age <= 18 ? $age : null;
        }

        return null;
    }

    private function composeAge(string $whole, ?string $fraction): ?float
    {
        $age = (float) $whole;
        if ($fraction !== null && $fraction !== '') {
            $age += ((float) $fraction) / 10;
        }

        return $age > 0 && $age <= 25 ? $age : null;
    }

    private function storeAgePrompted(Conversation $conversation, bool $asked): void
    {
        $data = is_array($conversation->intake_data) ? $conversation->intake_data : [];
        $state = is_array($data['participation'] ?? null) ? $data['participation'] : [];
        $state['age_prompted'] = $asked;
        $data['participation'] = $state;
        $conversation->forceFill(['intake_data' => $data]);
        if ($conversation->exists) {
            $conversation->save();
        }
    }

    private function looksLikeApply(string $text): bool
    {
        $lower = mb_strtolower($text);
        if ($lower === '') {
            return false;
        }

        foreach ([
            'apply', 'application', 'заявк', 'участв', 'participate', 'подати', 'подать',
            'daughter', 'доч', 'сын', 'син', 'child', 'kids', 'детк', 'діток', 'дітки',
            'how much', 'сколько', 'скільки', 'price', 'цена', 'ціна',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function lockParticipantFormInPrompt(string $prompt, array $config): string
    {
        $locked = $prompt;
        foreach ($this->participantUrls($config) as $url) {
            foreach (array_unique([$url, rtrim($url, '/'), rtrim($url, '/').'/']) as $variant) {
                if ($variant !== '') {
                    $locked = str_ireplace(
                        $variant,
                        '[PARTICIPANT FORM LOCKED — confirm US location and the child’s age first, do not send a form URL]',
                        $locked,
                    );
                }
            }
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function participantUrls(array $config): array
    {
        $urls = [];
        foreach (BotFormLinks::fromConfig($config) as $link) {
            if (($link['id'] ?? '') !== 'participant') {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function storePrompted(Conversation $conversation, ?string $prompted): void
    {
        $data = is_array($conversation->intake_data) ? $conversation->intake_data : [];
        $state = is_array($data['participation'] ?? null) ? $data['participation'] : [];
        $state['prompted'] = $prompted;
        $data['participation'] = $state;
        $conversation->forceFill(['intake_data' => $data]);
        if ($conversation->exists) {
            $conversation->save();
        }
    }

    private function locationHaystack(Conversation $conversation, string $customerText): string
    {
        $parts = [trim($customerText)];
        if ($conversation->exists) {
            $history = $conversation->messages()
                ->where('direction', ConversationMessage::DIRECTION_INBOUND)
                ->where('sender_type', ConversationMessage::SENDER_CUSTOMER)
                ->orderByDesc('id')
                ->limit(20)
                ->pluck('body')
                ->all();
            foreach ($history as $body) {
                $text = trim((string) $body);
                if ($text !== '' && $text !== trim($customerText)) {
                    $parts[] = $text;
                }
            }
        }

        return implode("\n", array_filter($parts));
    }

    private function lastBotBody(Conversation $conversation): ?string
    {
        if (! $conversation->exists) {
            return null;
        }

        $last = $conversation->messages()
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            return null;
        }

        $body = trim((string) $last->body);

        return $body !== '' ? $body : null;
    }

    private function inferPrompted(?string $lastBotText): ?string
    {
        if ($lastBotText === null || trim($lastBotText) === '') {
            return null;
        }

        $lower = mb_strtolower($lastBotText);
        if ($this->alreadyAsksTravel($lower)) {
            return self::PROMPTED_TRAVEL;
        }
        if ($this->alreadyAsksState($lower)) {
            return self::PROMPTED_STATE;
        }

        return null;
    }

    private function alreadyAsksState(string $text): bool
    {
        $lower = mb_strtolower($text);

        return (bool) preg_match('/(which|what|какой|каком|який|якому).{0,20}(state|штат)/u', $lower)
            || str_contains($lower, 'в каком штате')
            || str_contains($lower, 'у якому штаті');
    }

    private function alreadyAsksTravel(string $text): bool
    {
        $lower = mb_strtolower($text);

        return str_contains($lower, 'планируете ли вы быть в сша')
            || str_contains($lower, 'плануєте ви бути в сша')
            || str_contains($lower, 'be in the usa')
            || str_contains($lower, 'be in the us')
            || str_contains($lower, 'во время проведения')
            || str_contains($lower, 'під час проведення')
            || (str_contains($lower, 'сша') && str_contains($lower, 'показ'));
    }

    /**
     * @param  list<string>  $urls
     */
    private function containsAnyUrl(string $text, array $urls): bool
    {
        foreach ($urls as $url) {
            if ($url !== '' && str_contains($text, rtrim($url, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function withOnlineLinks(string $text, array $config, Conversation $conversation): string
    {
        $official = InstagramOutboundLinkButtons::officialUrls($config);
        $needsYoutube = ! str_contains($text, 'youtube.com') && ! str_contains($text, 'youtu.be');
        $needsInstagram = $conversation->channel !== 'instagram'
            && ! str_contains($text, 'instagram.com');

        $suffix = [];
        if ($needsInstagram) {
            $suffix[] = $official['instagram'];
        }
        if ($needsYoutube) {
            $suffix[] = $official['youtube'];
        }

        if ($suffix === []) {
            return $text;
        }

        return trim($text."\n".implode("\n", $suffix));
    }

    private function join(string $existing, string $question): string
    {
        $existing = trim($existing);
        if ($existing === '') {
            return $question;
        }

        return $existing."\n\n".$question;
    }

    private function copy(string $key, string $locale): string
    {
        $copy = [
            'ask_state' => [
                'en' => 'To send you the application, please tell me which U.S. state you are in.',
                'ru' => 'Чтобы отправить заявку, подскажите, пожалуйста, в каком штате США вы находитесь.',
                'uk' => 'Щоб надіслати заявку, підкажіть, будь ласка, в якому штаті США ви перебуваєте.',
                'ro' => 'Ca să vă trimit cererea, spuneți-mi vă rog în ce stat din SUA vă aflați.',
                'de' => 'Um die Anmeldung zu senden, sagen Sie uns bitte, in welchem US-Bundesstaat Sie sind.',
            ],
            'ask_travel' => [
                'en' => 'Young Fashion Show takes place in the USA, in cities like Los Angeles, Miami, Chicago, and New York. Please tell us: do you plan to be in the USA during the shows?',
                'ru' => 'Fashion show проходит в США, в таких городах как Лос-Анджелес, Майами, Чикаго и Нью-Йорк. Уточните, планируете ли вы быть в США во время проведения показов?',
                'uk' => 'Fashion show проходить у США, у таких містах як Лос-Анджелес, Маямі, Чикаго та Нью-Йорк. Уточніть, будь ласка, чи плануєте ви бути в США під час показів?',
                'ro' => 'Young Fashion Show are loc în SUA, în orașe precum Los Angeles, Miami, Chicago și New York. Ne puteți spune dacă plănuiți să fiți în SUA pe durata prezentărilor?',
                'es' => 'Young Fashion Show se celebra en EE. UU., en ciudades como Los Ángeles, Miami, Chicago y Nueva York. ¿Planean estar en Estados Unidos durante los desfiles?',
                'de' => 'Young Fashion Show findet in den USA statt, in Städten wie Los Angeles, Miami, Chicago und New York. Planen Sie, während der Shows in den USA zu sein?',
            ],
            'ask_age' => [
                'en' => 'How old is your child?',
                'ru' => 'Подскажите, пожалуйста, сколько лет вашему ребёнку?',
                'uk' => 'Підкажіть, будь ласка, скільки років вашій дитині?',
                'ro' => 'Ce vârstă are copilul dumneavoastră?',
                'es' => '¿Cuántos años tiene su hijo o hija?',
                'de' => 'Wie alt ist Ihr Kind?',
            ],
            'family_look' => [
                'en' => 'At this age children walk in our Family Look category — the child goes down the runway together with a parent, or with both parents. It is a very warm format and the little ones feel safe on stage. Would this format suit you?',
                'ru' => 'В этом возрасте детки выходят в категории Family Look — ребёнок идёт по подиуму вместе с родителем или сразу с двумя. Это очень тёплый формат, и малышам спокойнее на сцене. Подходит ли вам такой формат?',
                'uk' => 'У цьому віці дітки виходять у категорії Family Look — дитина йде подіумом разом із мамою чи татом або одразу з двома. Це дуже теплий формат, і малечі спокійніше на сцені. Чи підходить вам такий формат?',
                'ro' => 'La această vârstă copiii defilează în categoria Family Look — copilul merge pe podium împreună cu un părinte sau cu ambii părinți. Este un format foarte cald, iar cei mici se simt în siguranță pe scenă. Vi se potrivește acest format?',
                'es' => 'A esta edad los niños desfilan en la categoría Family Look: el niño camina por la pasarela junto a uno de los padres o con ambos. Es un formato muy cálido y los pequeños se sienten seguros en el escenario. ¿Les encaja este formato?',
                'de' => 'In diesem Alter laufen die Kinder in der Kategorie Family Look — das Kind geht gemeinsam mit einem Elternteil oder mit beiden über den Runway. Ein sehr warmes Format, in dem sich die Kleinen sicher fühlen. Passt dieses Format für Sie?',
            ],
            'too_young' => [
                'en' => 'Our runway welcomes children from 3 years old — with a parent in the Family Look category, and from 5 they can also walk on their own. We would be delighted to see your little one when the time comes, and meanwhile you are very welcome to follow our shows.',
                'ru' => 'На наш подиум мы приглашаем детей от 3 лет — в категории Family Look вместе с родителем, а с 5 лет ребёнок может выходить и сам. Будем очень рады видеть вашего малыша, когда придёт время, а пока приглашаем следить за нашими шоу.',
                'uk' => 'На наш подіум ми запрошуємо дітей від 3 років — у категорії Family Look разом із батьками, а з 5 років дитина може виходити й сама. Будемо дуже раді бачити вашу малечу, коли прийде час, а поки запрошуємо стежити за нашими шоу.',
                'ro' => 'Pe podiumul nostru invităm copii de la 3 ani — în categoria Family Look împreună cu un părinte, iar de la 5 ani copilul poate defila și singur. Ne-ar face mare plăcere să vă vedem micuțul când va veni momentul, iar până atunci vă invităm să urmăriți prezentările noastre.',
                'es' => 'A nuestra pasarela invitamos a niños desde los 3 años — en la categoría Family Look junto a uno de los padres, y desde los 5 el niño ya puede desfilar solo. Nos encantará ver a su pequeño cuando llegue el momento y, mientras tanto, les invitamos a seguir nuestros desfiles.',
                'de' => 'Auf unseren Runway laden wir Kinder ab 3 Jahren ein — in der Kategorie Family Look gemeinsam mit einem Elternteil, ab 5 Jahren kann das Kind auch allein laufen. Wir freuen uns sehr auf Ihren kleinen Star, wenn die Zeit gekommen ist, und laden Sie ein, unsere Shows zu verfolgen.',
            ],
            'travel_comment' => [
                'en' => 'In the comment field of the form, please note that you are able to come to the USA for the event.',
                'ru' => 'В поле «комментарии» отметьте, пожалуйста, что у вас есть возможность приехать в США на ивент.',
                'uk' => 'У полі «коментарі» зазначте, будь ласка, що у вас є можливість приїхати до США на івент.',
                'ro' => 'În câmpul de comentarii al formularului, menționați vă rog că puteți veni în SUA pentru eveniment.',
                'es' => 'En el campo de comentarios del formulario, indique por favor que pueden viajar a EE. UU. para el evento.',
                'de' => 'Bitte notieren Sie im Kommentarfeld des Formulars, dass Sie zum Event in die USA kommen können.',
            ],
            'not_coming' => [
                'en' => 'Thank you for your interest in Young Fashion Show. We would be happy to have you follow the events online.',
                'ru' => 'Спасибо за ваш интерес к Young Fashion Show. Приглашаем следить за событиями онлайн.',
                'uk' => 'Дякуємо за ваш інтерес до Young Fashion Show. Запрошуємо стежити за подіями онлайн.',
                'ro' => 'Vă mulțumim pentru interesul față de Young Fashion Show. Vă invităm să urmăriți evenimentele online.',
                'de' => 'Danke für Ihr Interesse an Young Fashion Show. Wir laden Sie ein, die Events online zu verfolgen.',
            ],
            'travel_costs' => [
                'en' => 'Please note: Young Fashion Show does not cover travel, flights, visas, or accommodation. An invitation or SMS is not a paid trip.',
                'ru' => 'Важно: компания не несёт расходы по приезду модели на шоу. Сообщение или приглашение не означает, что переезд будет оплачен.',
                'uk' => 'Важливо: компанія не несе витрат на приїзд моделі на шоу. Повідомлення чи запрошення не означає, що переїзд буде оплачено.',
                'ro' => 'Important: Young Fashion Show nu acoperă deplasarea, zborurile, vizele sau cazarea. Un mesaj sau o invitație nu înseamnă o călătorie plătită.',
                'es' => 'Importante: Young Fashion Show no cubre viaje, vuelos, visados ni alojamiento. Un mensaje o invitación no es un viaje pagado.',
            ],
        ];

        if (! isset($copy[$key][$locale])) {
            $locale = 'en';
        }

        return $copy[$key][$locale];
    }

    private function matchUsPlace(string $text): ?string
    {
        $lower = $this->foldForPlaceMatch($text);
        $named = $this->matchListedPlace($lower, $this->usPlaces());
        if ($named !== null) {
            return $named;
        }

        if ($this->isExplicitlyOutsideUsa($lower)) {
            return null;
        }

        if (preg_match('/\b(usa|u\.s\.a\.?|united states)\b/u', $lower)
            || str_contains($lower, 'сша')
            || str_contains($lower, 'в штате')
            || str_contains($lower, 'у штаті')) {
            return 'USA';
        }

        return null;
    }

    private function isExplicitlyOutsideUsa(string $lower): bool
    {
        return (bool) preg_match('/\b(not in the (usa|us|united states)|outside the (usa|us)|not in america)\b/u', $lower)
            || str_contains($lower, 'не в сша')
            || str_contains($lower, 'не в америке')
            || str_contains($lower, 'не в америці')
            || str_contains($lower, 'не из сша')
            || str_contains($lower, 'не із сша');
    }

    private function matchForeignPlace(string $text): ?string
    {
        $lower = $this->foldForPlaceMatch($text);

        if ($this->isExplicitlyOutsideUsa($lower)) {
            return $this->matchListedPlace($lower, $this->foreignPlaces()) ?? 'outside USA';
        }

        return $this->matchListedPlace($lower, $this->foreignPlaces());
    }

    /**
     * @param  array<string, string>  $places  needle => label
     */
    private function matchListedPlace(string $lower, array $places): ?string
    {
        foreach ($places as $needle => $label) {
            if (mb_strlen($needle) <= 3) {
                if (preg_match('/(^|[^\p{L}])'.preg_quote($needle, '/').'([^\p{L}]|$)/u', $lower) === 1) {
                    return $label;
                }
                continue;
            }
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function usPlaces(): array
    {
        return [
            'los angeles' => 'Los Angeles',
            'лос-анджелес' => 'Los Angeles',
            'лос анджелес' => 'Los Angeles',
            'new york' => 'New York',
            'нью-йорк' => 'New York',
            'нью йорк' => 'New York',
            'nyc' => 'New York',
            'miami' => 'Miami',
            'майами' => 'Miami',
            'маямі' => 'Miami',
            'chicago' => 'Chicago',
            'чикаго' => 'Chicago',
            'чікаго' => 'Chicago',
            'california' => 'California',
            'калифорн' => 'California',
            'каліфорн' => 'California',
            'florida' => 'Florida',
            'флорид' => 'Florida',
            'texas' => 'Texas',
            'техас' => 'Texas',
            'illinois' => 'Illinois',
            'иллинойс' => 'Illinois',
            'іллінойс' => 'Illinois',
            'new jersey' => 'New Jersey',
            'massachusetts' => 'Massachusetts',
            'pennsylvania' => 'Pennsylvania',
            'virginia' => 'Virginia',
            'north carolina' => 'North Carolina',
            'south carolina' => 'South Carolina',
            'georgia' => 'Georgia',
            'arizona' => 'Arizona',
            'nevada' => 'Nevada',
            'colorado' => 'Colorado',
            'washington' => 'Washington',
            'maryland' => 'Maryland',
            'michigan' => 'Michigan',
            'ohio' => 'Ohio',
            'minnesota' => 'Minnesota',
            'wisconsin' => 'Wisconsin',
            'indiana' => 'Indiana',
            'tennessee' => 'Tennessee',
            'missouri' => 'Missouri',
            'oregon' => 'Oregon',
            'connecticut' => 'Connecticut',
            'alabama' => 'Alabama',
            'alaska' => 'Alaska',
            'arkansas' => 'Arkansas',
            'delaware' => 'Delaware',
            'hawaii' => 'Hawaii',
            'idaho' => 'Idaho',
            'iowa' => 'Iowa',
            'kansas' => 'Kansas',
            'kentucky' => 'Kentucky',
            'louisiana' => 'Louisiana',
            'maine' => 'Maine',
            'mississippi' => 'Mississippi',
            'montana' => 'Montana',
            'nebraska' => 'Nebraska',
            'new hampshire' => 'New Hampshire',
            'new mexico' => 'New Mexico',
            'north dakota' => 'North Dakota',
            'oklahoma' => 'Oklahoma',
            'rhode island' => 'Rhode Island',
            'south dakota' => 'South Dakota',
            'utah' => 'Utah',
            'vermont' => 'Vermont',
            'west virginia' => 'West Virginia',
            'wyoming' => 'Wyoming',
            'brooklyn' => 'New York',
            'manhattan' => 'New York',
            'houston' => 'Texas',
            'dallas' => 'Texas',
            'austin' => 'Texas',
            'atlanta' => 'Georgia',
            'boston' => 'Massachusetts',
            'seattle' => 'Washington',
            'san francisco' => 'California',
            'san diego' => 'California',
            'las vegas' => 'Nevada',
            'orlando' => 'Florida',
            'tampa' => 'Florida',
            'hollywood' => 'California',
            'ny' => 'New York',
            'ca' => 'California',
            'fl' => 'Florida',
            'tx' => 'Texas',
            'il' => 'Illinois',
            'nj' => 'New Jersey',
            'wa' => 'Washington',
            'az' => 'Arizona',
            'nv' => 'Nevada',
            'co' => 'Colorado',
            'ga' => 'Georgia',
            'nc' => 'North Carolina',
            'pa' => 'Pennsylvania',
            'ma' => 'Massachusetts',
            'va' => 'Virginia',
            'md' => 'Maryland',
            'mi' => 'Michigan',
            'tn' => 'Tennessee',
            'dc' => 'Washington DC',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function foreignPlaces(): array
    {
        return [
            'nigeria' => 'Nigeria',
            'нигери' => 'Nigeria',
            'нігері' => 'Nigeria',
            'lagos' => 'Nigeria',
            'лагос' => 'Nigeria',
            'ghana' => 'Ghana',
            'гана' => 'Ghana',
            'kenya' => 'Kenya',
            'кени' => 'Kenya',
            'кені' => 'Kenya',
            'cameroon' => 'Cameroon',
            'камерун' => 'Cameroon',
            'uganda' => 'Uganda',
            'уганд' => 'Uganda',
            'tanzania' => 'Tanzania',
            'танзан' => 'Tanzania',
            'ethiopia' => 'Ethiopia',
            'эфиоп' => 'Ethiopia',
            'ефіоп' => 'Ethiopia',
            'south africa' => 'South Africa',
            'юар' => 'South Africa',
            'senegal' => 'Senegal',
            'сенегал' => 'Senegal',
            'ivory coast' => 'Ivory Coast',
            'côte d’ivoire' => 'Ivory Coast',
            'cote d\'ivoire' => 'Ivory Coast',
            'morocco' => 'Morocco',
            'марокко' => 'Morocco',
            'egypt' => 'Egypt',
            'египет' => 'Egypt',
            'єгипет' => 'Egypt',
            'algeria' => 'Algeria',
            'congo' => 'Congo',
            'rwanda' => 'Rwanda',
            'zambia' => 'Zambia',
            'zimbabwe' => 'Zimbabwe',
            'africa' => 'Africa',
            'африка' => 'Africa',
            'ukraine' => 'Ukraine',
            'украин' => 'Ukraine',
            'україн' => 'Ukraine',
            'киев' => 'Ukraine',
            'київ' => 'Ukraine',
            'одесса' => 'Ukraine',
            'одеса' => 'Ukraine',
            'львов' => 'Ukraine',
            'львів' => 'Ukraine',
            'russia' => 'Russia',
            'росси' => 'Russia',
            'росі' => 'Russia',
            'москва' => 'Russia',
            'kazakhstan' => 'Kazakhstan',
            'казахстан' => 'Kazakhstan',
            'belarus' => 'Belarus',
            'беларус' => 'Belarus',
            'білорус' => 'Belarus',
            'poland' => 'Poland',
            'польш' => 'Poland',
            'польщ' => 'Poland',
            'germany' => 'Germany',
            'deutschland' => 'Germany',
            'герман' => 'Germany',
            'німеччин' => 'Germany',
            'france' => 'France',
            'франц' => 'France',
            'italy' => 'Italy',
            'итал' => 'Italy',
            'італ' => 'Italy',
            'spain' => 'Spain',
            'испан' => 'Spain',
            'іспан' => 'Spain',
            'england' => 'United Kingdom',
            'britain' => 'United Kingdom',
            'великобритан' => 'United Kingdom',
            'англі' => 'United Kingdom',
            'англи' => 'United Kingdom',
            'canada' => 'Canada',
            'канад' => 'Canada',
            'mexico' => 'Mexico',
            'мексик' => 'Mexico',
            'brazil' => 'Brazil',
            'бразил' => 'Brazil',
            'india' => 'India',
            'инди' => 'India',
            'інді' => 'India',
            'china' => 'China',
            'китай' => 'China',
            'turkey' => 'Turkey',
            'турц' => 'Turkey',
            'uae' => 'UAE',
            'dubai' => 'UAE',
            'дубай' => 'UAE',
            'israel' => 'Israel',
            'израил' => 'Israel',
            'ізраїл' => 'Israel',
            'moldova' => 'Moldova',
            'молдов' => 'Moldova',
            'romania' => 'Romania',
            'românia' => 'Romania',
            'румын' => 'Romania',
            'румун' => 'Romania',
            'uzbekistan' => 'Uzbekistan',
            'узбекистан' => 'Uzbekistan',
            'грузи' => 'Georgia (country)',
            'tbilisi' => 'Georgia (country)',
            'тбилиси' => 'Georgia (country)',
            'тбілісі' => 'Georgia (country)',
        ];
    }

    /**
     * A clear statement that they will come to the show after all.
     */
    private function announcesTravel(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach ([
            'приед', 'приїд', 'сможем приехать', 'зможемо приїхати', 'будем в сша', 'будемо в сша',
            'we will come', 'we can come', 'we will be in the us', 'we are coming',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Yes, that format suits us" — an agreement that is not a bare yes.
     */
    private function acceptsFormat(string $text): bool
    {
        $lower = trim(mb_strtolower($text));

        foreach ([
            'подходит', 'підходить', 'устраивает', 'влаштовує', 'согласн', 'згодн',
            'suits us', 'that works', 'sounds good', 'we are fine with', 'we agree',
            'ne convine', 'nos parece bien', 'passt uns', 'ist in ordnung',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isAffirmative(string $text): bool
    {
        $lower = trim(mb_strtolower($text));
        if ($lower === '') {
            return false;
        }

        if (preg_match('/^(да|так|yes|yeah|ok|okay|окей|ага|ja|si|sí|da)\b/u', $lower) === 1) {
            return true;
        }

        foreach ([
            'yes', 'yeah', 'yep', 'sure', 'of course', 'we will', "we'll", 'we are planning',
            'планируем', 'планируем быть', 'будем в сша', 'будем', 'собираемся',
            'плануємо', 'будемо', 'так, будемо', 'так будемо',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return in_array($lower, ['да', 'да.', 'да!', 'так', 'так.', 'так!', 'ok', 'okay', 'yes.', 'yes!'], true);
    }

    private function isNegative(string $text): bool
    {
        $lower = trim(mb_strtolower($text));
        if ($lower === '') {
            return false;
        }

        foreach ([
            'not planning', "won't", 'cannot', "can't", 'не планир', 'не плану',
            'не будем', 'не будемо', 'не сможем', 'не зможемо', 'нет, не', 'ні, не',
            'не подходит', 'не підходить', 'не устраивает', 'не влаштовує', 'не интересует',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        if (preg_match('/^(no|nope|нет|ні|nu|nein|non)\b/u', $lower) === 1) {
            return true;
        }

        return in_array($lower, ['нет', 'нет.', 'ні', 'ні.', 'no.', 'no', 'nu', 'nu.'], true);
    }

    private function foldForPlaceMatch(string $text): string
    {
        $lower = mb_strtolower($text);

        return strtr($lower, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'ü' => 'u', 'ö' => 'o', 'ä' => 'a',
            'і' => 'и', 'ї' => 'и', 'є' => 'е', 'ґ' => 'г',
        ]);
    }
}
