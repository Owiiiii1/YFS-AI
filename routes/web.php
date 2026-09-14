<?php

use App\Http\Controllers\JfsSsoController;
use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $ticket = trim((string) request()->query('ticket', ''));
    if ($ticket !== '') {
        return redirect()->route('sso.login', request()->only(['ticket', 'source']));
    }

    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/sso/login', [JfsSsoController::class, 'login'])->name('sso.login');

Route::get('/privacy-policy', [LegalController::class, 'privacyPolicy'])->name('legal.privacy');
Route::get('/terms-of-service', [LegalController::class, 'termsOfService'])->name('legal.terms');
Route::get('/data-deletion', [LegalController::class, 'dataDeletion'])->name('legal.data-deletion');
Route::get('/data-deletion/status/{confirmation_code}', [LegalController::class, 'dataDeletionStatus'])
    ->name('legal.data-deletion.status');

// Owl Admin routes
require __DIR__.'/owl-admin-pages.php';
require __DIR__.'/owl-admin-auth.php';
