<?php

namespace App\Console\Commands;

use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use Illuminate\Console\Command;
use Throwable;

class ValidateStructuredBotPrompts extends Command
{
    protected $signature = 'bot:prompts:validate';

    protected $description = 'Validate structured prompt topics and bound templates';

    public function handle(): int
    {
        $setting = BotSetting::instance();
        $config = is_array($setting->prompt_config) ? $setting->prompt_config : [];
        $errors = [];

        try {
            BotPromptSchema::assertValid($config);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info('Structured prompt config is valid.');
        $this->line('Schema version: '.BotPromptSchema::VERSION);
        $this->line('Revision: '.(int) $setting->prompt_revision);
        $this->line('Topics: '.count(BotPromptSchema::sectionKeys($config)));
        $this->line('Templates: '.count(BotPromptSchema::templateKeys($config)));

        return self::SUCCESS;
    }
}
