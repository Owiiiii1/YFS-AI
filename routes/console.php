<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('instagram:tokens:refresh')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/instagram-token-refresh.log'));

Schedule::command('facebook:tokens:check')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/facebook-token-check.log'));

Schedule::command('bot:retry-missed-replies')
    ->everyTwoMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/bot-retry-missed-replies.log'));

Schedule::command('bot:recover-operator-handoffs')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/bot-recover-operator-handoffs.log'));

Schedule::command('bot:reenable-after-manual-inactivity')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/bot-manual-mode-reenable.log'));

Schedule::command('instagram:reconcile-conversations')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/instagram-reconcile.log'));

Schedule::command('ai:purge-analysis-telemetry --days=90')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->onOneServer();
