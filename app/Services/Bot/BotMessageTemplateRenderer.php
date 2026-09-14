<?php

namespace App\Services\Bot;

class BotMessageTemplateRenderer
{
    public function __construct(
        private readonly BotPromptConfigService $config,
    ) {}

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $key, string $language, array $variables = []): string
    {
        BotDecisionTraceService::template($key);

        return $this->config->template($key, $language, $variables);
    }

    /**
     * Render an internal label without classifying it as the customer-facing reply.
     *
     * @param  array<string, string|int|float>  $variables
     */
    public function renderInternal(string $key, string $language, array $variables = []): string
    {
        return $this->config->template($key, $language, $variables);
    }
}
