<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaAppSettings;
use App\Services\Meta\MetaFacebookWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class MetaFacebookWebhookController extends Controller
{
    public function __construct(
        private readonly MetaAppSettings $metaAppSettings,
        private readonly MetaFacebookWebhookService $webhookService,
    ) {}

    public function handle(Request $request): Response|SymfonyResponse|string
    {
        if ($request->isMethod('GET')) {
            return $this->verify($request);
        }

        if ($request->isMethod('POST')) {
            return $this->receive($request);
        }

        return response('Method Not Allowed', 405);
    }

    private function verify(Request $request): Response|SymfonyResponse|string
    {
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode', ''));
        $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token', ''));
        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge', ''));

        $expectedToken = $this->metaAppSettings->webhookVerifyToken();

        if ($mode !== 'subscribe' || ! filled($expectedToken) || ! hash_equals($expectedToken, $token)) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    private function receive(Request $request): Response
    {
        $payload = $request->all();

        try {
            $this->webhookService->handle(is_array($payload) ? $payload : []);
        } catch (Throwable $exception) {
            Log::warning('Facebook webhook processing failed.', [
                'message' => $exception->getMessage(),
            ]);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
