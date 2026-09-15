<?php

namespace App\Http\Middleware;

use App\Services\ElevenLabs\ElevenLabsWebhookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateElevenLabsPostCallWebhook
{
    public function __construct(
        private readonly ElevenLabsWebhookSignatureVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.elevenlabs.post_call_webhook_secret', '');
        $header = $request->header('ElevenLabs-Signature');

        if (! $this->verifier->verify($request->getContent(), $header, $secret)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
