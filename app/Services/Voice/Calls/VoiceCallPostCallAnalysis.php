<?php

namespace App\Services\Voice\Calls;

final class VoiceCallPostCallAnalysis
{
    /**
     * @param  list<string>  $unresolvedQuestions
     */
    public function __construct(
        public readonly ?string $intent,
        public readonly ?string $department,
        public readonly bool $humanFollowupRequired,
        public readonly bool $callbackRequested,
        public readonly bool $callbackCommittedByAgent,
        public readonly bool $liveFollowupCreated,
        public readonly ?string $summary,
        public readonly array $unresolvedQuestions,
        public readonly ?string $customerName = null,
        public readonly ?string $childName = null,
        public readonly ?string $showCity = null,
        public readonly ?string $callbackPhone = null,
        public readonly ?string $preferredCallbackTime = null,
        public readonly ?string $reason = null,
    ) {}

    public static function none(bool $liveFollowupCreated = false): self
    {
        return new self(
            intent: null,
            department: null,
            humanFollowupRequired: false,
            callbackRequested: false,
            callbackCommittedByAgent: false,
            liveFollowupCreated: $liveFollowupCreated,
            summary: null,
            unresolvedQuestions: [],
        );
    }

    public function needsSafetyNetFollowup(): bool
    {
        return $this->callbackCommittedByAgent || $this->humanFollowupRequired;
    }
}
