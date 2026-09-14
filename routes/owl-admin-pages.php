<?php

use App\Http\Controllers\Admin\FacebookSettingsController;
use App\Http\Controllers\Admin\InstagramSettingsController;
use App\Http\Controllers\AiPromptAnalysisController;
use App\Http\Controllers\BotManagementController;
use App\Http\Controllers\DialogsController;
use App\Http\Controllers\QuestionnairesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\ElevenLabsSettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use App\Http\Controllers\Settings\UserController as SettingsUserController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use OwlSolutions\CustomAdminKit\Support\AdminRouteMiddleware;

/*
| Admin preset pages (v0.3).
| Loaded from routes/web.php via:
| require __DIR__.'/owl-admin-pages.php';
*/

Route::middleware(AdminRouteMiddleware::stack())->group(function () {
    Route::get('/dashboard', static fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/call-center', static fn () => Inertia::render('CallCenter/Index'))->name('call-center.index');

    Route::get('/customers', static fn () => redirect()->route('questionnaires.index'))->name('customers.index');
    Route::post('/customers', static fn () => redirect()->route('questionnaires.index'))->name('customers.store');
    Route::patch('/customers/{customer}', static fn () => redirect()->route('questionnaires.index'))->name('customers.update');
    Route::delete('/customers/{customer}', static fn () => redirect()->route('questionnaires.index'))->name('customers.destroy');

    Route::get('/orders', static fn () => redirect()->route('questionnaires.index'))->name('orders.index');

    Route::get('/services', [ServicesController::class, 'index'])->name('services.index');
    Route::post('/services', [ServicesController::class, 'store'])->name('services.store');
    Route::patch('/services/{service}', [ServicesController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServicesController::class, 'destroy'])->name('services.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::get('/calendar', static fn () => redirect()->route('questionnaires.index'))->name('calendar.index');

    Route::get('/dialogs', static fn () => redirect()->route('dialogs.instagram'))->name('dialogs.index');
    Route::get('/dialogs/instagram', [DialogsController::class, 'index'])->name('dialogs.instagram');
    Route::get('/dialogs/facebook', static fn () => redirect()->route('dialogs.instagram'))->name('dialogs.facebook');
    Route::patch('/dialogs/{conversation}/bot', [DialogsController::class, 'updateBot'])->name('dialogs.bot.update');
    Route::post('/dialogs/{conversation}/messages', [DialogsController::class, 'storeMessage'])->name('dialogs.messages.store');

    Route::get('/questionnaires', [QuestionnairesController::class, 'index'])->name('questionnaires.index');
    Route::post('/questionnaires/{botReply}/read', [QuestionnairesController::class, 'markRead'])->name('questionnaires.read');

    Route::get('/bot-management', [BotManagementController::class, 'index'])->name('bot-management.index');
    Route::post('/bot-management', [BotManagementController::class, 'update'])->name('bot-management.update');
    Route::post('/bot-management/enabled', [BotManagementController::class, 'updateEnabled'])->name('bot-management.enabled.update');
    Route::post('/bot-management/settings', [BotManagementController::class, 'updateSettings'])->name('bot-management.settings.update');
    Route::post('/bot-management/form-links', [BotManagementController::class, 'updateFormLinks'])->name('bot-management.form-links.update');
    Route::prefix('/bot-management/ai-analysis')->name('bot-management.ai-analysis.')->group(function () {
        Route::get('/active', [AiPromptAnalysisController::class, 'active'])->name('active');
        Route::get('/history', [AiPromptAnalysisController::class, 'history'])->name('history');
        Route::get('/dialogs', [AiPromptAnalysisController::class, 'searchDialogs'])->name('dialogs');
        Route::post('/sessions', [AiPromptAnalysisController::class, 'store'])->name('store');
        Route::get('/sessions/{session}', [AiPromptAnalysisController::class, 'show'])->name('show');
        Route::post('/sessions/{session}/analyze', [AiPromptAnalysisController::class, 'analyze'])->name('analyze');
        Route::post('/sessions/{session}/instruction', [AiPromptAnalysisController::class, 'instruction'])->name('instruction');
        Route::post('/sessions/{session}/preview', [AiPromptAnalysisController::class, 'preview'])->name('preview');
        Route::post('/sessions/{session}/approve', [AiPromptAnalysisController::class, 'approve'])->name('approve');
        Route::post('/sessions/{session}/apply', [AiPromptAnalysisController::class, 'apply'])->name('apply');
        Route::post('/sessions/{session}/finish', [AiPromptAnalysisController::class, 'finish'])->name('finish');
    });

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/elevenlabs', [ElevenLabsSettingsController::class, 'save'])->name('elevenlabs.save');
    Route::post('/settings/telegram/bot', [TelegramSettingsController::class, 'connectBot'])->name('telegram.bot.connect');
    Route::post('/settings/telegram/bot/disconnect', [TelegramSettingsController::class, 'disconnectBot'])->name('telegram.bot.disconnect');
    Route::post('/settings/telegram/channel', [TelegramSettingsController::class, 'connectChannel'])->name('telegram.channel.connect');
    Route::post('/settings/telegram/channel/disconnect', [TelegramSettingsController::class, 'disconnectChannel'])->name('telegram.channel.disconnect');
    Route::post('/settings/language', [SettingsController::class, 'updateLanguage'])->name('settings.language.update');
    Route::post('/settings/users', [SettingsUserController::class, 'store'])->name('settings.users.store');
    Route::patch('/settings/users/{user}', [SettingsUserController::class, 'update'])->name('settings.users.update');
    Route::delete('/settings/users/{user}', [SettingsUserController::class, 'destroy'])->name('settings.users.destroy');

    Route::get('/app-settings', function () {
        return Inertia::render('AppSettings/Index');
    })->name('app-settings.index');

    Route::get('/ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
    Route::post('/ai-settings/analysis/check', [AiSettingsController::class, 'checkAnalysis'])->name('ai-settings.analysis.check');
    Route::post('/ai-settings/analysis/activate', [AiSettingsController::class, 'activateAnalysis'])->name('ai-settings.analysis.activate');
    Route::post('/ai-settings/analysis/deactivate', [AiSettingsController::class, 'deactivateAnalysis'])->name('ai-settings.analysis.deactivate');
    Route::post('/ai-settings/{provider}/key', [AiSettingsController::class, 'saveKey'])->name('ai-settings.save-key');
    Route::post('/ai-settings/{provider}/check', [AiSettingsController::class, 'check'])->name('ai-settings.check');
    Route::post('/ai-settings/{provider}/roles/{role}/activate', [AiSettingsController::class, 'activateRole'])->name('ai-settings.roles.activate');
    Route::post('/ai-settings/roles/{role}/deactivate', [AiSettingsController::class, 'deactivateRole'])->name('ai-settings.roles.deactivate');
    Route::post('/ai-settings/{provider}/activate', [AiSettingsController::class, 'activate'])->name('ai-settings.activate');
    Route::post('/ai-settings/deactivate', [AiSettingsController::class, 'deactivate'])->name('ai-settings.deactivate');

    Route::get('/instagram', [InstagramSettingsController::class, 'show'])->name('instagram.index');
    Route::post('/instagram', [InstagramSettingsController::class, 'update'])->name('instagram.update');
    Route::post('/instagram/test', [InstagramSettingsController::class, 'testConnection'])->name('instagram.test');
    Route::get('/instagram/connect/meta', [InstagramSettingsController::class, 'redirectToMeta'])->name('instagram.meta.redirect');
    Route::get('/instagram/connect/meta/callback', [InstagramSettingsController::class, 'handleMetaCallback'])->name('instagram.meta.callback');
    Route::post('/instagram/disconnect', [InstagramSettingsController::class, 'disconnect'])->name('instagram.disconnect');

    Route::get('/facebook', [FacebookSettingsController::class, 'show'])->name('facebook.index');
    Route::post('/facebook', [FacebookSettingsController::class, 'update'])->name('facebook.update');
    Route::post('/facebook/test', [FacebookSettingsController::class, 'testConnection'])->name('facebook.test');
    Route::get('/facebook/connect/meta', [FacebookSettingsController::class, 'redirectToMeta'])->name('facebook.meta.redirect');
    Route::get('/facebook/connect/meta/callback', [FacebookSettingsController::class, 'handleMetaCallback'])->name('facebook.meta.callback');
    Route::post('/facebook/disconnect', [FacebookSettingsController::class, 'disconnect'])->name('facebook.disconnect');

    Route::get('/statistics/logs', function () {
        return Inertia::render('Statistics/Logs');
    })->name('statistics.logs');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
