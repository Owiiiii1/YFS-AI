<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ElevenLabs\ElevenLabsSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ElevenLabsSettingsController extends Controller
{
    public function __construct(
        private readonly ElevenLabsSettingsService $settings,
    ) {}

    public function save(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'api_key' => ['nullable', 'string', 'max:4096'],
            ]);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('settings.index', ['tab' => 'elevenlabs']));
        }

        $setting = $this->settings->saveAndVerify($validated['api_key'] ?? null);

        session(['settings_tab' => 'elevenlabs']);

        if (! filled($setting->api_key)) {
            return redirect()
                ->route('settings.index', ['tab' => 'elevenlabs'])
                ->with('elevenlabs_status', 'ElevenLabs is not configured.');
        }

        if ($setting->is_connected) {
            return redirect()
                ->route('settings.index', ['tab' => 'elevenlabs'])
                ->with('elevenlabs_status', 'ElevenLabs connected.');
        }

        return redirect()
            ->route('settings.index', ['tab' => 'elevenlabs'])
            ->withErrors([
                'elevenlabs' => $setting->last_error ?: 'ElevenLabs connection failed.',
            ]);
    }
}
