<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Meta\InstagramDeauthorizeService;
use App\Services\Meta\MetaSignedRequestControllerSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaInstagramDeauthorizeController extends Controller
{
    public function __construct(
        private readonly MetaSignedRequestControllerSupport $signedRequest,
        private readonly InstagramDeauthorizeService $deauthorizeService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->signedRequest->validatedPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $this->deauthorizeService->handle($this->signedRequest->platformUserId($payload));

        return response()->json(['success' => true], 200);
    }
}
