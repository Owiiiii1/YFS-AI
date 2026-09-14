<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaDataDeletionRequest;
use App\Services\Meta\MetaSignedRequestControllerSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaInstagramDataDeletionController extends Controller
{
    public function __construct(
        private readonly MetaSignedRequestControllerSupport $signedRequest,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->signedRequest->validatedPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $confirmationCode = Str::lower(bin2hex(random_bytes(16)));

        MetaDataDeletionRequest::query()->create([
            'confirmation_code' => $confirmationCode,
            'platform_user_id' => $this->signedRequest->platformUserId($payload),
            'status' => MetaDataDeletionRequest::STATUS_RECEIVED,
            'requested_at' => now(),
        ]);

        Log::info('meta.instagram.data_deletion.received', [
            'has_user_id' => filled($this->signedRequest->platformUserId($payload)),
        ]);

        return response()->json([
            'url' => url('/data-deletion/status/'.$confirmationCode),
            'confirmation_code' => $confirmationCode,
        ], 200);
    }
}
