<?php

namespace App\Services\Voice\Followup;

use App\Models\VoiceFollowup;

final class VoiceFollowupTelegramFormatter
{
    public function format(VoiceFollowup $followup, bool $recovered = false): string
    {
        $lines = [
            $recovered ? '⚠️ Voice · Follow-up recovered after call' : $this->liveTitle($followup),
            '',
            'Department: '.$this->departmentLabel($followup->department),
        ];

        $this->add($lines, 'Customer', $followup->customer_name);
        $this->add($lines, 'Child', $followup->child_name);
        $this->add($lines, 'Show', $followup->show_city);
        $this->add($lines, 'Callback', $followup->callback_phone);
        $this->add($lines, 'Preferred time', $followup->preferred_callback_time);

        $reason = trim((string) $followup->reason);
        if ($reason !== '') {
            $lines[] = '';
            $lines[] = 'Reason:';
            $lines[] = $reason;
        }

        $summary = trim((string) $followup->summary);
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = 'Summary:';
            $lines[] = $summary;
        }

        $adminUrl = $this->adminUrl($followup->voice_call_id);
        if ($adminUrl !== null) {
            $lines[] = '';
            $lines[] = $adminUrl;
        }

        return implode("\n", $lines);
    }

    private function liveTitle(VoiceFollowup $followup): string
    {
        return $followup->callback_requested
            ? '📞 Voice · Callback requested'
            : '📞 Voice · Manager request';
    }

    private function departmentLabel(string $department): string
    {
        return match ($department) {
            VoiceFollowup::DEPARTMENT_SUPPORT => 'Support',
            default => 'Sales',
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private function add(array &$lines, string $label, ?string $value): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $lines[] = $label.': '.$value;
    }

    private function adminUrl(mixed $voiceCallId): ?string
    {
        if (! is_numeric($voiceCallId) || (int) $voiceCallId <= 0) {
            return null;
        }

        return url('/call-center?call='.(int) $voiceCallId);
    }
}
