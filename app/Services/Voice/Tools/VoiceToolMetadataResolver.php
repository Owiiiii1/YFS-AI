<?php

namespace App\Services\Voice\Tools;

final class VoiceToolMetadataResolver
{
    public function resolve(VoiceToolInterface $tool): VoiceToolMetadata
    {
        if ($tool instanceof VoiceToolMetadataProvider) {
            return $tool->metadata();
        }

        return VoiceToolMetadata::defaults();
    }
}
