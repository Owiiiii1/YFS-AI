<?php

namespace Tests\Support;

use App\Models\VoiceContact;

final class MemoryVoiceContact extends VoiceContact
{
    public $exists = true;

    public function save(array $options = []): bool
    {
        $this->syncOriginal();

        return true;
    }
}
