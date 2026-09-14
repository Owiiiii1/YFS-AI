<?php

namespace App\Services\Voice\Tools;

interface VoiceToolMetadataProvider
{
    public function metadata(): VoiceToolMetadata;
}
