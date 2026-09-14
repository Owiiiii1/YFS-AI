<?php

namespace Tests\Unit;

use App\Services\Instagram\HumanHandoffClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HumanHandoffClassifierTest extends TestCase
{
    private HumanHandoffClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new HumanHandoffClassifier;
    }

    #[Test]
    public function yes_after_manager_offer_is_affirmative(): void
    {
        $this->assertTrue($this->classifier->isHandoffAffirmative('да'));
        $this->assertTrue($this->classifier->isAwaitingOperatorOffer('operator'));
        $this->assertTrue($this->classifier->isAwaitingOperatorOffer('operator_offer'));
        $this->assertFalse($this->classifier->isAwaitingOperatorOffer('form'));
    }

    #[Test]
    public function manager_connect_question_is_an_operator_offer(): void
    {
        $offer = 'Хотите, я подключу менеджера, чтобы подробнее обсудить детали и возможные варианты?';

        $this->assertTrue($this->classifier->isOperatorOfferText($offer));
        $this->assertFalse($this->classifier->isHandoffClaimText($offer));
    }

    #[Test]
    public function connecting_operator_reply_is_a_handoff_claim(): void
    {
        $claim = 'Подключаю оператора. Пожалуйста, ожидайте ответа.';

        $this->assertTrue($this->classifier->isHandoffClaimText($claim));
        $this->assertFalse($this->classifier->isOperatorOfferText($claim));
    }

    #[Test]
    public function follow_up_that_says_operator_will_join_is_a_handoff_claim(): void
    {
        $this->assertTrue($this->classifier->isHandoffClaimText(
            'Спасибо за уточнение! Зафиксировала информацию для менеджера. Скоро оператор подключится и ответит вам.',
        ));
    }

    #[Test]
    public function ukrainian_yes_and_operator_offer_are_recognized(): void
    {
        $this->assertTrue($this->classifier->isHandoffAffirmative('Так'));
        $this->assertTrue($this->classifier->isOperatorOfferText(
            'Підключити вас до живого оператора?',
        ));
    }

    #[Test]
    public function form_question_is_not_an_operator_offer(): void
    {
        $this->assertFalse($this->classifier->isOperatorOfferText(
            'Хотите заполнить заявку на участие?',
        ));
    }
}
