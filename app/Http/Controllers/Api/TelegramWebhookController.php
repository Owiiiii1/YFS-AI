<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramBotService $telegramBotService,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            if (! Schema::hasTable('telegram_settings')) {
                return response('ok', 200);
            }

            $settings = TelegramSetting::query()->orderBy('id')->first();
            if ($settings === null || ! $settings->isBotConnected()) {
                return response('ok', 200);
            }

            $accepted = $this->telegramBotService->processWebhook(
                $settings,
                $request->header('X-Telegram-Bot-Api-Secret-Token'),
            );
            if (! $accepted) {
                return response('Forbidden', 403);
            }
        } catch (Throwable $exception) {
            Log::warning('telegram.webhook.failed', [
                'message' => $exception->getMessage(),
            ]);
        }

        return response('ok', 200);
    }
}
