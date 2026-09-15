<?php

namespace App\Http\Controllers\CallCenter;

use App\Http\Controllers\Controller;
use App\Models\VoiceAssistantSetting;
use App\Support\VoiceAssistantSettingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoiceBotSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        VoiceAssistantSetting::ensureDefaults();

        $sections = VoiceAssistantSetting::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (VoiceAssistantSetting $section): array => [
                'id' => $section->id,
                'key' => $section->key,
                'title' => $section->title,
                'instructions' => (string) $section->instructions,
                'enabled' => (bool) $section->enabled,
                'sort_order' => (int) $section->sort_order,
            ])
            ->values()
            ->all();

        $requested = (string) $request->query('tab', 'general');
        $tab = VoiceAssistantSettingCatalog::isKnownKey($requested) ? $requested : 'general';

        return Inertia::render('CallCenter/BotSettings', [
            'tab' => $tab,
            'sections' => $sections,
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        if (! VoiceAssistantSettingCatalog::isKnownKey($key)) {
            abort(404);
        }

        VoiceAssistantSetting::ensureDefaults();

        $validated = $request->validate([
            'instructions' => ['nullable', 'string', 'max:20000'],
            'enabled' => ['required', 'boolean'],
        ]);

        $section = VoiceAssistantSetting::query()->where('key', $key)->firstOrFail();
        $section->forceFill([
            'instructions' => $validated['instructions'] ?? '',
            'enabled' => $request->boolean('enabled'),
        ])->save();

        return redirect()
            ->route('call-center.bot-settings', ['tab' => $key])
            ->with('voice_bot_status', 'Bot settings saved.');
    }
}
