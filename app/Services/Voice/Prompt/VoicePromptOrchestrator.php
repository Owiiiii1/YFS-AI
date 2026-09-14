<?php

namespace App\Services\Voice\Prompt;

use App\Services\Voice\Context\VoiceSessionContext;
use App\Services\Voice\Tools\TestVoiceTool;
use Illuminate\Support\Facades\Log;

final class VoicePromptOrchestrator
{
    public function assemble(VoiceSessionContext $context, string $userText): VoiceAssembledPrompt
    {
        $topic = $context->currentTopic ?: 'general';
        $path = $topic === 'test_status' ? 'tool' : 'fast';
        $allowedTools = $topic === 'test_status' ? [TestVoiceTool::NAME] : [];
        if ($topic === 'general') {
            $path = 'tool';
            $allowedTools = [TestVoiceTool::NAME];
        }

        $sections = [
            'global' => $this->globalSection(),
            'session' => $this->sessionSection($context),
            'topic' => $this->topicSection($topic, $context),
        ];
        $text = implode("\n\n", $sections);
        $sectionNames = ['global', 'session', 'topic:'.$topic];

        $assembled = new VoiceAssembledPrompt(
            text: $text,
            activeTopic: $topic,
            responsePath: $path,
            allowedTools: $allowedTools,
            sectionNames: $sectionNames,
            promptChars: mb_strlen($text),
        );

        Log::info('voice.prompt.assembled', [
            'active_topic' => $assembled->activeTopic,
            'section_names' => $assembled->sectionNames,
            'allowed_tools' => $assembled->allowedTools,
            'prompt_chars' => $assembled->promptChars,
            'response_path' => $assembled->responsePath,
        ]);

        return $assembled;
    }

    private function globalSection(): string
    {
        return <<<'TEXT'
[GLOBAL]
You are the Young Fashion Show voice assistant.
Speak naturally and concisely in the caller's language.
Do not invent customer, event, payment, order, or CRM information.
If a fact is already present in SESSION context, answer from that context. Do not call a tool for preloaded facts.
If a fact is missing from SESSION context, use an allowed tool. Do not guess.
YFS Core and Bitrix24 production connectors are not connected. Never present test-only data as a live show or CRM record.
If you cannot help, say so and offer to involve a person.
Source precedence: SESSION preloaded context, then tool results. Do not mix them up as production truth.
TEXT;
    }

    private function sessionSection(VoiceSessionContext $context): string
    {
        $yfs = $context->yfsContext;
        $yfsBlock = 'none';
        if (is_array($yfs)) {
            $yfsBlock = 'event_name='.(string) ($yfs['event_name'] ?? '')
                .'; message='.(string) ($yfs['message'] ?? '')
                .'; availability='.(string) ($yfs['availability'] ?? '')
                .'; source='.(string) ($yfs['source'] ?? '');
        }

        return "[SESSION]\n"
            ."language: {$context->detectedLanguage}\n"
            .'caller_phone: '.($context->callerPhone ?: 'unknown')."\n"
            ."customer_context: none\n"
            ."yfs_context: {$yfsBlock}\n"
            ."bitrix_context: none\n"
            ."conversation_state: {$context->conversationState}\n"
            .'freshness: yfs='.(string) ($context->freshness['yfs'] ?? 'unknown');
    }

    private function topicSection(string $topic, VoiceSessionContext $context): string
    {
        if ($topic === 'test_event') {
            return <<<'TEXT'
[TOPIC:test_event]
The caller is asking about the YFS test event.
This fact is already preloaded in SESSION yfs_context (test-only).
Do not call tools. Answer from SESSION.
Say clearly that this is test-only context, not a real production show.
Allowed tools: none.
TEXT;
        }

        if ($topic === 'test_status') {
            return <<<'TEXT'
[TOPIC:test_status]
The caller wants the latest YFS test status.
That value is not in SESSION preload. You MUST call get_current_yfs_test_context before answering.
Do not invent the status. Use the tool result.
Say clearly that this is test-only context.
Allowed tools: get_current_yfs_test_context.
TEXT;
        }

        return "[TOPIC:general]\n"
            ."Use SESSION facts when they answer the question.\n"
            ."Call get_current_yfs_test_context only for latest test status or other missing test facts.\n"
            .'Language: '.$context->detectedLanguage;
    }
}
