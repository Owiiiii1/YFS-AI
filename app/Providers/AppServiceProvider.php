<?php

namespace App\Providers;

use App\Services\Voice\Tools\TestVoiceTool;
use App\Services\Voice\Tools\VoiceToolRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VoiceToolRegistry::class, function () {
            return new VoiceToolRegistry([
                new TestVoiceTool(),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
