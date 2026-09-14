<?php

namespace App\Http\Controllers;

use App\Models\AiRoleConnection;
use App\Models\AiProviderSetting;
use App\Models\BotSetting;
use App\Models\FacebookPageAccount;
use App\Models\InstagramAccount;
use App\Services\Bot\BotPromptApplyService;
use App\Services\Bot\BotPromptPatchService;
use App\Support\BotFormLinks;
use App\Support\BotPromptSchema;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotManagementController extends Controller
{
    public function __construct(
        private readonly BotPromptApplyService $promptApply,
        private readonly BotPromptPatchService $promptPatches,
    ) {}

    public function index(): Response
    {
        $setting = BotSetting::instance();
        $analysisConnection = AiRoleConnection::query()
            ->where('role', AiRoleConnection::ROLE_PROMPT_ANALYSIS)
            ->where('is_connected', true)
            ->first();
        $analysisReady = $analysisConnection !== null
            && AiProviderSetting::query()
                ->where('provider', $analysisConnection->key_source_provider)
                ->where('is_connected', true)
                ->whereNotNull('api_key')
                ->exists();

        return Inertia::render('Bot/Index', [
            'bot' => [
                'bot_enabled' => (bool) $setting->bot_enabled,
                'prompt_config' => $setting->prompt_config,
                'prompt_revision' => (int) $setting->prompt_revision,
                'structured_prompts_ready' => (bool) $setting->structured_prompts_ready,
                'manual_mode_reenable_days' => (int) $setting->manual_mode_reenable_days,
                'ai_analysis_ready' => $analysisReady,
                'form_links' => BotFormLinks::fromConfig(is_array($setting->prompt_config) ? $setting->prompt_config : []),
            ],
            'prompt_sections' => collect($setting->prompt_config['topics'] ?? [])
                ->filter(fn (mixed $topic): bool => is_array($topic))
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        return $this->updateStructured($request);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'manual_mode_reenable_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        BotSetting::instance()->forceFill([
            'manual_mode_reenable_days' => (int) $validated['manual_mode_reenable_days'],
        ])->save();

        return back()->with('bot_status', 'Настройки бота сохранены.');
    }

    public function updateEnabled(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('bot_enabled');

        $setting = BotSetting::instance();
        $setting->forceFill(['bot_enabled' => $enabled])->save();
        $this->syncChannelBotEnabled($enabled);

        return back()->with('bot_status', $enabled ? 'Бот включён.' : 'Бот выключен.');
    }

    public function updateFormLinks(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'form_links' => ['required', 'array', 'min:1', 'max:20'],
            'form_links.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'form_links.*.url' => ['required', 'url', 'max:500'],
            'form_links.*.labels' => ['nullable', 'array'],
            'form_links.*.labels.en' => ['required', 'string', 'max:120'],
            'form_links.*.labels.ru' => ['nullable', 'string', 'max:120'],
            'form_links.*.labels.uk' => ['nullable', 'string', 'max:120'],
        ]);

        $setting = BotSetting::instance();
        $config = is_array($setting->prompt_config) ? $setting->prompt_config : DefaultBotPromptConfig::make();
        $values = is_array($config['business_values'] ?? null) ? $config['business_values'] : [];
        $config['business_values'] = BotFormLinks::applyToBusinessValues($values, $validated['form_links']);
        BotPromptSchema::assertValid($config);

        $setting->forceFill([
            'prompt_config' => $config,
            'prompt_revision' => (int) $setting->prompt_revision + 1,
        ])->save();

        return back()->with('bot_status', 'Ссылки на анкеты сохранены.');
    }

    private function updateStructured(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_enabled' => ['required'],
            'prompt_revision' => ['required', 'integer', 'min:0'],
            'prompt_config' => ['required', 'array'],
            'prompt_config.schema_version' => ['required', 'integer', 'in:'.BotPromptSchema::VERSION],
            'prompt_config.topics' => ['required', 'array', 'min:1', 'max:100'],
            'prompt_config.topics.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'prompt_config.topics.*.label' => ['nullable', 'string', 'max:120'],
            'prompt_config.topics.*.labels' => ['nullable', 'array'],
            'prompt_config.topics.*.labels.en' => ['nullable', 'string', 'max:120'],
            'prompt_config.topics.*.labels.ru' => ['nullable', 'string', 'max:120'],
            'prompt_config.topics.*.labels.uk' => ['nullable', 'string', 'max:120'],
            'prompt_config.topics.*.descriptions' => ['nullable', 'array'],
            'prompt_config.topics.*.descriptions.en' => ['nullable', 'string', 'max:500'],
            'prompt_config.topics.*.descriptions.ru' => ['nullable', 'string', 'max:500'],
            'prompt_config.topics.*.descriptions.uk' => ['nullable', 'string', 'max:500'],
            'prompt_config.topics.*.always_include' => ['nullable', 'boolean'],
            'prompt_config.topics.*.text' => ['nullable', 'string', 'max:100000'],
            'prompt_config.topics.*.templates' => ['nullable', 'array', 'max:50'],
            'prompt_config.topics.*.templates.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'prompt_config.topics.*.templates.*.label' => ['required', 'string', 'max:120'],
            'prompt_config.topics.*.templates.*.texts' => ['required', 'array'],
            'prompt_config.topics.*.templates.*.texts.en' => ['nullable', 'string', 'max:10000'],
            'prompt_config.topics.*.templates.*.texts.ru' => ['nullable', 'string', 'max:10000'],
            'prompt_config.topics.*.templates.*.texts.uk' => ['nullable', 'string', 'max:10000'],
            'prompt_config.business_values' => ['nullable', 'array'],
            'prompt_config.business_values.follow_up_delay_hours' => ['nullable', 'numeric', 'min:0', 'max:720'],
            'prompt_config.business_values.business_hours_start' => ['nullable', 'integer', 'min:0', 'max:23'],
            'prompt_config.business_values.business_hours_end' => ['nullable', 'integer', 'min:1', 'max:24'],
            'prompt_config.business_values.business_timezone' => ['nullable', 'timezone'],
        ]);

        $config = $this->normalizePromptConfig($validated['prompt_config']);
        BotPromptSchema::assertValid($config);
        $this->promptPatches->assertPlaceholdersValid($config);

        $setting = $this->promptApply->apply(
            $config,
            (int) $validated['prompt_revision'],
            $request->user()?->id,
            'Automatic snapshot before admin update',
            (bool) $request->boolean('bot_enabled'),
        );

        $this->syncChannelBotEnabled($setting->bot_enabled);

        return back()->with('bot_status', __('client.bot.settings_saved'));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizePromptConfig(array $config): array
    {
        $topics = [];
        foreach (array_values((array) ($config['topics'] ?? [])) as $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $templates = [];
            foreach (array_values((array) ($topic['templates'] ?? [])) as $template) {
                if (! is_array($template)) {
                    continue;
                }

                $templates[] = [
                    ...$template,
                    'id' => (string) ($template['id'] ?? ''),
                    'label' => (string) ($template['label'] ?? $template['id'] ?? 'message'),
                    'texts' => [
                        'en' => (string) data_get($template, 'texts.en', ''),
                        'ru' => (string) data_get($template, 'texts.ru', ''),
                        'uk' => (string) data_get($template, 'texts.uk', ''),
                    ],
                ];
            }

            $topics[] = [
                ...$topic,
                'id' => (string) ($topic['id'] ?? ''),
                'always_include' => (bool) ($topic['always_include'] ?? false),
                'text' => (string) ($topic['text'] ?? ''),
                'templates' => $templates,
            ];
        }

        $config['topics'] = $topics;
        $values = is_array($config['business_values'] ?? null) ? $config['business_values'] : [];
        $config['business_values'] = BotFormLinks::applyToBusinessValues(
            $values,
            BotFormLinks::fromConfig(['business_values' => $values]),
        );

        return $config;
    }

    private function syncChannelBotEnabled(bool $enabled): void
    {
        $instagram = InstagramAccount::primary();
        $instagramSettings = is_array($instagram->settings) ? $instagram->settings : [];
        $instagramSettings['bot_enabled'] = $enabled;
        $instagram->settings = $instagramSettings;
        $instagram->save();

        $facebook = FacebookPageAccount::primary();
        $facebookSettings = is_array($facebook->settings) ? $facebook->settings : [];
        $facebookSettings['bot_enabled'] = $enabled;
        $facebook->settings = $facebookSettings;
        $facebook->save();
    }
}
