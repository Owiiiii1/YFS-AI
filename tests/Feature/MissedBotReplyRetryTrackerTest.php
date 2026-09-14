<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Services\Messaging\MissedBotReplyRetryTracker;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MissedBotReplyRetryTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-19 12:10:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fresh_unanswered_inbound_is_retryable_after_min_age(): void
    {
        $tracker = new MissedBotReplyRetryTracker;
        $message = $this->inbound(sentAt: Carbon::now()->subSeconds(91));

        $this->assertTrue($tracker->canRetry($message));
    }

    public function test_does_not_retry_until_min_age(): void
    {
        $tracker = new MissedBotReplyRetryTracker;
        $message = $this->inbound(sentAt: Carbon::now()->subSeconds(30));

        $this->assertFalse($tracker->canRetry($message));
    }

    public function test_does_not_retry_after_max_age(): void
    {
        $tracker = new MissedBotReplyRetryTracker;
        $message = $this->inbound(sentAt: Carbon::now()->subMinutes(721));

        $this->assertFalse($tracker->canRetry($message));
    }

    public function test_backoff_blocks_immediate_second_attempt(): void
    {
        $tracker = new MissedBotReplyRetryTracker;
        $message = $this->inbound(sentAt: Carbon::now()->subMinutes(5));

        $tracker->markFailed($message);

        $this->assertFalse($tracker->canRetry($message));

        Carbon::setTestNow(Carbon::now()->addSeconds(119));
        $this->assertFalse($tracker->canRetry($message));

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->assertTrue($tracker->canRetry($message));
    }

    public function test_stops_after_max_attempts(): void
    {
        $tracker = new MissedBotReplyRetryTracker;
        $message = $this->inbound(sentAt: Carbon::now()->subMinutes(5));

        for ($i = 0; $i < MissedBotReplyRetryTracker::MAX_ATTEMPTS; $i++) {
            $tracker->markFailed($message);
        }

        Carbon::setTestNow(Carbon::now()->addMinutes(20));

        $this->assertFalse($tracker->canRetry($message));
    }

    public function test_clear_allows_retry_again(): void
    {
        $tracker = new MissedBotReplyRetryTracker;
        $message = $this->inbound(sentAt: Carbon::now()->subMinutes(5));

        $tracker->markFailed($message);
        $tracker->clear($message);

        $this->assertTrue($tracker->canRetry($message));
    }

    private function inbound(Carbon $sentAt): ConversationMessage
    {
        $message = new ConversationMessage;
        $message->id = 4242;
        $message->conversation_id = 243;
        $message->sent_at = $sentAt;

        return $message;
    }
}
