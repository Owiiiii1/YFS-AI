<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateVoiceRuntime
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.voice_runtime.internal_token', '');
        if ($expected === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $provided = substr($header, 7);
        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
