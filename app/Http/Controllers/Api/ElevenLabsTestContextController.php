<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ElevenLabsTestContextController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'event_name' => 'YFS Test Event',
            'status' => 'active',
            'message' => 'Voice session context is working',
            'source' => 'yfs_ai_test',
        ]);
    }
}
