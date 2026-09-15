<?php

use App\Http\Controllers\Api\MetaFacebookWebhookController;
use App\Http\Controllers\Api\MetaInstagramDataDeletionController;
use App\Http\Controllers\Api\MetaInstagramDeauthorizeController;
use App\Http\Controllers\Api\MetaInstagramWebhookController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\ElevenLabsTestContextController;
use App\Http\Controllers\Api\ElevenLabsConversationInitiationController;
use App\Http\Controllers\Api\VoiceContextController;
use App\Http\Controllers\Api\VoiceRuntimeConfigController;
use App\Http\Controllers\Api\VoiceSessionTurnController;
use App\Http\Controllers\Api\VoiceToolController;
use App\Http\Middleware\AuthenticateElevenLabsTool;
use App\Http\Middleware\AuthenticateVoiceRuntime;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], '/webhooks/meta/instagram', [MetaInstagramWebhookController::class, 'handle']);
Route::match(['GET', 'POST'], '/webhooks/meta/facebook', [MetaFacebookWebhookController::class, 'handle']);
Route::post('/meta/instagram/deauthorize', MetaInstagramDeauthorizeController::class)
    ->name('api.meta.instagram.deauthorize');
Route::post('/meta/instagram/data-deletion', MetaInstagramDataDeletionController::class)
    ->name('api.meta.instagram.data-deletion');
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->name('api.telegram.webhook');
Route::post('/voice/tools/test-context', ElevenLabsTestContextController::class)
    ->middleware(AuthenticateElevenLabsTool::class)
    ->name('api.voice.tools.test-context');
Route::post('/voice/context', VoiceContextController::class)
    ->middleware(AuthenticateElevenLabsTool::class)
    ->name('api.voice.context');
Route::post('/voice/elevenlabs/conversation-initiation', ElevenLabsConversationInitiationController::class)
    ->middleware(AuthenticateElevenLabsTool::class)
    ->name('api.voice.elevenlabs.conversation-initiation');

Route::get('/internal/voice-runtime/config', VoiceRuntimeConfigController::class)
    ->middleware(AuthenticateVoiceRuntime::class)
    ->name('api.internal.voice-runtime.config');

Route::middleware(AuthenticateVoiceRuntime::class)->group(function () {
    Route::post('/internal/voice/session/turn', VoiceSessionTurnController::class)
        ->name('api.internal.voice.session.turn');
    Route::get('/internal/voice/tools', [VoiceToolController::class, 'catalog'])
        ->name('api.internal.voice.tools.catalog');
    Route::post('/internal/voice/tools/execute', [VoiceToolController::class, 'execute'])
        ->name('api.internal.voice.tools.execute');
});
