<?php

namespace App\Services\Ai;

use App\Models\AiPromptAnalysisSession;
use Illuminate\Validation\ValidationException;

class AiAnalysisSessionStateMachine
{
    /**
     * @param  list<string>  $allowedStatuses
     */
    public function require(AiPromptAnalysisSession $session, array $allowedStatuses, string $action): void
    {
        if (! in_array($session->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'session' => "Нельзя выполнить «{$action}» на текущем этапе.",
            ]);
        }
    }

    public function move(AiPromptAnalysisSession $session, string $status, int $step): void
    {
        $session->forceFill([
            'status' => $status,
            'step' => $step,
            'error_message' => null,
        ])->save();
    }
}
