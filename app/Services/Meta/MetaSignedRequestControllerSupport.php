<?php

namespace App\Services\Meta;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaSignedRequestControllerSupport
{
    public function __construct(
        private readonly MetaSignedRequestVerifier $verifier,
        private readonly MetaAppSettings $metaAppSettings,
    ) {}

    /**
     * @return array<string, mixed>|JsonResponse
     */
    public function validatedPayload(Request $request): array|JsonResponse
    {
        $signedRequest = trim((string) $request->input('signed_request', ''));
        if ($signedRequest === '') {
            return response()->json(['error' => 'Bad Request'], 400);
        }

        $appSecret = $this->metaAppSettings->instagramAppSecret();
        if (! filled($appSecret)) {
            Log::warning('meta.instagram.signed_request.secret_missing');

            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            return $this->verifier->parse($signedRequest, $appSecret);
        } catch (InvalidMetaSignedRequestException $exception) {
            Log::warning('meta.instagram.signed_request.rejected', [
                'reason' => $exception->reason,
            ]);

            return response()->json(
                ['error' => $exception->reason === InvalidMetaSignedRequestException::REASON_MALFORMED ? 'Bad Request' : 'Forbidden'],
                $exception->httpStatus(),
            );
        }
    }

    public function platformUserId(array $payload): ?string
    {
        return $this->verifier->platformUserId($payload);
    }
}
