<?php

namespace App\Providers;

use App\Services\Voice\Tools\GetPublicShowsVoiceTool;
use App\Services\Voice\Tools\GetShowBrandsVoiceTool;
use App\Services\Voice\Tools\ResolveCustomerIdentityVoiceTool;
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
        $this->app->singleton(VoiceToolRegistry::class, function ($app) {
            return new VoiceToolRegistry([
                $app->make(TestVoiceTool::class),
                $app->make(GetPublicShowsVoiceTool::class),
                $app->make(GetShowBrandsVoiceTool::class),
                $app->make(ResolveCustomerIdentityVoiceTool::class),
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
