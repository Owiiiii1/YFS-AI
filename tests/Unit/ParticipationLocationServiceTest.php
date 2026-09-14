<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Services\Bot\ParticipationLocationService;
use App\Services\Instagram\ConversationLanguageResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParticipationLocationServiceTest extends TestCase
{
    private ParticipationLocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ParticipationLocationService(new ConversationLanguageResolver());
    }

    #[Test]
    public function us_state_is_a_confirmed_location(): void
    {
        $state = $this->service->detect('Мы в California, две дочки');

        $this->assertSame(ParticipationLocationService::STATUS_US, $state['status']);
        $this->assertTrue($this->service->locationAllows($state));
    }

    #[Test]
    public function chicago_in_ukrainian_is_us(): void
    {
        $state = $this->service->detect('З чого розпочати. Ми у Чікаго');

        $this->assertSame(ParticipationLocationService::STATUS_US, $state['status']);
        $this->assertSame('Chicago', $state['place']);
        $this->assertTrue($this->service->locationAllows($state));
    }

    #[Test]
    public function deutschland_is_outside_the_us(): void
    {
        $state = $this->service->detect('Wir kommen aus Deutschland');

        $this->assertSame(ParticipationLocationService::STATUS_OUTSIDE_US, $state['status']);
    }

    #[Test]
    public function romania_with_diacritics_is_outside_the_us(): void
    {
        $state = $this->service->detect('Suntem din România');

        $this->assertSame(ParticipationLocationService::STATUS_OUTSIDE_US, $state['status']);
        $this->assertSame('Romania', $state['place']);
    }

    #[Test]
    public function nigeria_asks_about_travel_and_blocks_the_form(): void
    {
        $state = $this->service->detect('We are in Nigeria');

        $this->assertSame(ParticipationLocationService::STATUS_OUTSIDE_US, $state['status']);
        $this->assertFalse($this->service->locationAllows($state));
        $this->assertFalse($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function yes_after_travel_question_confirms_the_location(): void
    {
        $state = $this->service->detect(
            'да, будем в США в декабре',
            ['status' => ParticipationLocationService::STATUS_OUTSIDE_US, 'place' => 'Nigeria'],
            'Fashion show проходит в США, в таких городах как Лос-Анджелес, Майами, Чикаго и Нью-Йорк. Уточните, планируете ли вы быть в США во время проведения показов?',
        );

        $this->assertSame(ParticipationLocationService::STATUS_WILL_TRAVEL, $state['status']);
        $this->assertTrue($this->service->locationAllows($state));
    }

    #[Test]
    public function no_after_travel_question_does_not_allow_the_form(): void
    {
        $state = $this->service->detect(
            'нет',
            ['status' => ParticipationLocationService::STATUS_OUTSIDE_US, 'place' => 'Nigeria'],
            'планируете ли вы быть в США во время проведения показов?',
        );

        $this->assertSame(ParticipationLocationService::STATUS_NOT_COMING, $state['status']);
        $this->assertFalse($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function yes_to_apply_is_not_treated_as_travel_yes(): void
    {
        $state = $this->service->detect(
            'да',
            ['status' => ParticipationLocationService::STATUS_UNKNOWN],
            'Хотите подать заявку на участие ребёнка?',
        );

        $this->assertSame(ParticipationLocationService::STATUS_UNKNOWN, $state['status']);
        $this->assertFalse($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function not_in_usa_is_outside(): void
    {
        $state = $this->service->detect('мы не в США');

        $this->assertSame(ParticipationLocationService::STATUS_OUTSIDE_US, $state['status']);
    }

    #[Test]
    public function foreign_plus_us_city_means_they_will_travel(): void
    {
        $state = $this->service->detect('Живём в Lagos, but we will be in Miami in December');

        $this->assertSame(ParticipationLocationService::STATUS_WILL_TRAVEL, $state['status']);
        $this->assertTrue($this->service->locationAllows($state));
    }

    #[Test]
    public function location_alone_does_not_release_the_form(): void
    {
        $state = $this->service->detect('Мы в Miami');

        $this->assertTrue($this->service->locationAllows($state));
        $this->assertFalse($this->service->ageAllows($state));
        $this->assertFalse($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function child_age_above_five_releases_the_form(): void
    {
        $state = $this->service->detect('Мы в Miami, дочке 8 лет');

        $this->assertSame(8.0, $state['age']);
        $this->assertSame(ParticipationLocationService::AGE_SOLO, $state['age_status']);
        $this->assertTrue($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function bare_number_after_the_age_question_counts_as_age(): void
    {
        $state = $this->service->detect(
            '7',
            ['status' => ParticipationLocationService::STATUS_US, 'place' => 'Miami'],
            'Подскажите, пожалуйста, сколько лет вашему ребёнку?',
        );

        $this->assertSame(7.0, $state['age']);
        $this->assertTrue($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function preschooler_needs_family_look_before_the_form(): void
    {
        $state = $this->service->detect('We are in Chicago, my daughter is 4');

        $this->assertSame(ParticipationLocationService::AGE_FAMILY_LOOK, $state['age_status']);
        $this->assertFalse($this->service->allowsParticipantForm($state));

        $confirmed = $this->service->detect(
            'да, подходит',
            $state,
            'Для деток младше 5 лет у нас есть категория Family Look — ребёнок выходит на подиум вместе с родителем. Подходит ли вам такой формат?',
        );

        $this->assertSame(ParticipationLocationService::AGE_FAMILY_LOOK_OK, $confirmed['age_status']);
        $this->assertTrue($this->service->allowsParticipantForm($confirmed));
    }

    #[Test]
    public function months_mean_the_child_is_too_young(): void
    {
        $state = $this->service->detect('нам 8 месяцев');

        $this->assertSame(ParticipationLocationService::AGE_TOO_YOUNG, $state['age_status']);
        $this->assertFalse($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function child_under_three_gets_a_warm_wait_message_without_the_form(): void
    {
        $conversation = new Conversation();
        $conversation->intake_data = ['participation' => [
            'status' => ParticipationLocationService::STATUS_US,
            'place' => 'Miami',
            'age' => 2,
            'age_status' => ParticipationLocationService::AGE_TOO_YOUNG,
        ]];

        $gated = $this->service->gateReply(
            $conversation,
            "Please fill out:\nhttps://form.youngfashionshow.com/crm_form_ppigu/",
            [],
            'My daughter is 2, we want to participate',
        );

        $this->assertStringNotContainsString('form.youngfashionshow.com', $gated);
        $this->assertStringContainsString('from 3 years old', $gated);
        $this->assertStringNotContainsStringIgnoringCase('not suitable', $gated);
    }

    #[Test]
    public function two_daughters_is_not_an_age(): void
    {
        $state = $this->service->detect('У меня две дочки, живём в Майами');

        $this->assertNull($state['age']);
        $this->assertSame(ParticipationLocationService::AGE_UNKNOWN, $state['age_status']);
    }

    #[Test]
    public function unknown_apply_reply_cannot_keep_the_form_link(): void
    {
        $conversation = new Conversation();
        $conversation->intake_data = ['participation' => [
            'status' => ParticipationLocationService::STATUS_UNKNOWN,
        ]];

        $gated = $this->service->gateReply(
            $conversation,
            "Заповніть заявку:\nhttps://form.youngfashionshow.com/crm_form_ppigu/",
            [],
            'Хочу дізнатися про участь, у мене дві дочки',
        );

        $this->assertStringNotContainsString('form.youngfashionshow.com', $gated);
        $this->assertStringContainsString('штаті', $gated);
    }

    #[Test]
    public function us_family_without_a_known_age_is_asked_about_the_child(): void
    {
        $conversation = new Conversation();
        $conversation->intake_data = ['participation' => [
            'status' => ParticipationLocationService::STATUS_US,
            'place' => 'California',
        ]];

        $gated = $this->service->gateReply(
            $conversation,
            "Please fill out:\nhttps://form.youngfashionshow.com/crm_form_ppigu/",
            [],
            'We want to participate with our daughter',
        );

        $this->assertStringNotContainsString('form.youngfashionshow.com', $gated);
        $this->assertStringContainsString('How old', $gated);
    }

    #[Test]
    public function us_family_with_a_qualifying_child_keeps_the_form_link(): void
    {
        $conversation = new Conversation();
        $conversation->intake_data = ['participation' => [
            'status' => ParticipationLocationService::STATUS_US,
            'place' => 'California',
            'age' => 9,
            'age_status' => ParticipationLocationService::AGE_SOLO,
        ]];

        $gated = $this->service->gateReply(
            $conversation,
            "Please fill out:\nhttps://form.youngfashionshow.com/crm_form_ppigu/",
            [],
            'She is 9',
        );

        $this->assertStringContainsString('form.youngfashionshow.com', $gated);
    }

    #[Test]
    public function travelling_family_is_asked_to_note_it_in_the_comment_field(): void
    {
        $conversation = new Conversation();
        $conversation->intake_data = ['participation' => [
            'status' => ParticipationLocationService::STATUS_WILL_TRAVEL,
            'place' => 'Nigeria / Miami',
            'age' => 10,
            'age_status' => ParticipationLocationService::AGE_SOLO,
        ]];

        $gated = $this->service->gateReply(
            $conversation,
            "Please fill out:\nhttps://form.youngfashionshow.com/crm_form_ppigu/",
            [],
            'He is 10',
        );

        $this->assertStringContainsString('form.youngfashionshow.com', $gated);
        $this->assertStringContainsString('comment field', $gated);
    }

    #[Test]
    public function a_confirmed_traveller_is_not_downgraded_by_mentioning_their_country(): void
    {
        $state = $this->service->detect(
            'мы из Нигерии, но приедем',
            [
                'status' => ParticipationLocationService::STATUS_WILL_TRAVEL,
                'place' => 'outside USA',
                'age' => 4,
                'age_status' => ParticipationLocationService::AGE_FAMILY_LOOK_OK,
            ],
        );

        $this->assertSame(ParticipationLocationService::STATUS_WILL_TRAVEL, $state['status']);
        $this->assertTrue($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function an_unrelated_message_keeps_the_confirmed_state(): void
    {
        $state = $this->service->detect(
            'окей, что дальше?',
            [
                'status' => ParticipationLocationService::STATUS_US,
                'place' => 'Miami',
                'age' => 8,
                'age_status' => ParticipationLocationService::AGE_SOLO,
            ],
        );

        $this->assertSame(ParticipationLocationService::STATUS_US, $state['status']);
        $this->assertSame(8.0, $state['age']);
        $this->assertTrue($this->service->allowsParticipantForm($state));
    }

    #[Test]
    public function someone_who_changes_their_mind_can_still_travel(): void
    {
        $state = $this->service->detect(
            'мы всё-таки приедем в декабре',
            ['status' => ParticipationLocationService::STATUS_NOT_COMING, 'place' => 'Nigeria'],
        );

        $this->assertSame(ParticipationLocationService::STATUS_WILL_TRAVEL, $state['status']);
    }

    #[Test]
    public function an_explicit_request_for_the_form_is_never_refused(): void
    {
        $conversation = new Conversation();
        $conversation->intake_data = ['participation' => [
            'status' => ParticipationLocationService::STATUS_UNKNOWN,
        ]];

        $gated = $this->service->gateReply(
            $conversation,
            "Here is the application:\nhttps://form.youngfashionshow.com/crm_form_ppigu/",
            [],
            'Please send the form',
        );

        $this->assertStringContainsString('form.youngfashionshow.com', $gated);
        $this->assertTrue($this->service->customerRequestsForm('дайте ссылку на заявку'));
    }
}
