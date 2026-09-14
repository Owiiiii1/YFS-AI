<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TelegramSettingsController extends Controller
{
    public function __construct(
        private readonly TelegramBotService $telegramBotService,
    ) {}

    public function connectBot(Request $request): RedirectResponse
    {
        $validated = $this->validateTelegram($request, [
            'bot_token' => ['required', 'string', 'max:255'],
        ]);

        $settings = TelegramSetting::current();

        try {
            $this->telegramBotService->connectBot($settings, $validated['bot_token']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->markBotFailed($settings, $exception->getMessage());

            return $this->backToTelegram()->withErrors(['telegram' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->markBotFailed($settings, $exception->getMessage());

            return $this->backToTelegram()->withErrors(['telegram' => 'Could not connect the Telegram bot.']);
        }

        return $this->backToTelegram()->with('telegram_status', 'Telegram bot connected.');
    }

    public function disconnectBot(): RedirectResponse
    {
        $this->telegramBotService->disconnectBot(TelegramSetting::current());

        return $this->backToTelegram()->with('telegram_status', 'Telegram bot disconnected.');
    }

    public function connectChannel(Request $request): RedirectResponse
    {
        $validated = $this->validateTelegram($request, [
            'channel' => ['required', 'string', 'max:500'],
            'thread' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = TelegramSetting::current();

        try {
            $this->telegramBotService->connectChannel(
                $settings,
                $validated['channel'],
                $validated['thread'] ?? null,
            );
        } catch (RuntimeException $exception) {
            $settings->forceFill([
                'channel_status' => TelegramSetting::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            return $this->backToTelegram()->withErrors(['telegram_channel' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $settings->forceFill([
                'channel_status' => TelegramSetting::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            return $this->backToTelegram()->withErrors(['telegram_channel' => 'Could not connect the Telegram channel or group.']);
        }

        return $this->backToTelegram()->with('telegram_status', 'Telegram channel or group connected.');
    }

    public function disconnectChannel(): RedirectResponse
    {
        $this->telegramBotService->disconnectChannel(TelegramSetting::current());

        return $this->backToTelegram()->with('telegram_status', 'Telegram channel or group disconnected.');
    }

    private function markBotFailed(TelegramSetting $settings, string $error): void
    {
        $settings->forceFill([
            'bot_status' => TelegramSetting::STATUS_FAILED,
            'last_error' => $error,
        ])->save();
    }

    private function backToTelegram(): RedirectResponse
    {
        session(['settings_tab' => 'telegram']);

        return redirect()->route('settings.index', ['tab' => 'telegram']);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validateTelegram(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('settings.index', ['tab' => 'telegram']));
        }
    }
}
