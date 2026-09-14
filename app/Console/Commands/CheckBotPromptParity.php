<?php

namespace App\Console\Commands;

use App\Models\BotSetting;
use App\Models\Conversation;
use App\Services\Bot\BotPromptAssembler;
use App\Services\Instagram\BotConversationContextBuilder;
use Illuminate\Console\Command;

class CheckBotPromptParity extends Command
{
    protected $signature = 'bot:prompts:parity';

    protected $description = 'Compare legacy and structured prompt behavior without sending messages';

    public function handle(
        BotConversationContextBuilder $legacyBuilder,
        BotPromptAssembler $structuredAssembler,
    ): int {
        $setting = BotSetting::instance();
        $conversation = Conversation::query()->latest('id')->first();

        if ($conversation === null) {
            $this->components->error('No conversation is available for a read-only parity check.');

            return self::FAILURE;
        }

        $latestMessage = (string) $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('sent_at')
            ->latest('id')
            ->value('body');

        $legacy = $legacyBuilder->buildSystemPrompt($conversation, $latestMessage);

        $candidate = clone $setting;
        $candidate->structured_prompts_ready = true;
        $structured = $structuredAssembler->buildSystemPrompt($conversation, $latestMessage, $candidate);

        $checks = [
            'current pickup address' => (string) data_get($setting->prompt_config, 'business_values.pickup_address'),
            'price objection' => 'what budget',
            'Ukrainian distinction' => 'Ukrainian is not Russian',
            'rush manager flow' => 'rush order',
            'calendar authority' => 'calendar',
            'one-question rule' => 'one question',
            'flavor policy' => 'flavor',
            'payment separation' => 'dedicated payment state',
        ];

        $errors = [];
        foreach ($checks as $label => $needle) {
            if ($needle === '' || stripos($structured, $needle) === false) {
                $errors[] = "Structured prompt is missing {$label}.";
            }
        }

        if (stripos($structured, '5227 N. Oakview') !== false) {
            $errors[] = 'Structured prompt contains the obsolete pickup address.';
        }
        if (preg_match('/more than (?:the next )?2 months/i', $structured)) {
            $errors[] = 'Structured prompt contains the obsolete two-month horizon.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info('Structured prompt passed critical behavior parity checks.');
        $this->line('Read-only conversation: #'.$conversation->id);
        $this->line('Legacy prompt chars: '.mb_strlen($legacy));
        $this->line('Structured prompt chars: '.mb_strlen($structured));
        $this->line('Flavor catalog loaded: '.(str_contains($structured, 'FLAVOR CATALOG') ? 'yes' : 'no (conditional)'));

        return self::SUCCESS;
    }
}
