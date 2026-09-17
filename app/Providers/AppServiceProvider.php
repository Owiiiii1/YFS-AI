<?php

namespace App\Providers;

use App\Services\Bitrix\BitrixIdentityGateway;
use App\Services\Bitrix\BitrixReadOnlyIdentityClient;
use App\Services\Bitrix\BitrixYfsLinker;
use App\Services\Jfs\JfsReadService;
use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Tools\GetCustomerContextVoiceTool;
use App\Services\Voice\Tools\GetExtendedIdentitySearchStatusVoiceTool;
use App\Services\Voice\Tools\GetPublicShowsVoiceTool;
use App\Services\Voice\Tools\GetShowBrandsVoiceTool;
use App\Services\Voice\Tools\ResolveCustomerIdentityVoiceTool;
use App\Services\Voice\Tools\StartExtendedIdentitySearchVoiceTool;
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
        $this->app->singleton(BitrixIdentityGateway::class, BitrixReadOnlyIdentityClient::class);
        $this->app->singleton(BitrixYfsLinker::class);
        $this->app->singleton(CustomerIdentityResolver::class, function ($app) {
            return new CustomerIdentityResolver(
                $app->make(JfsReadService::class),
                $app->make(BitrixIdentityGateway::class),
                $app->make(BitrixYfsLinker::class),
            );
        });

        $this->app->singleton(VoiceToolRegistry::class, function ($app) {
            return new VoiceToolRegistry([
                $app->make(TestVoiceTool::class),
                $app->make(GetPublicShowsVoiceTool::class),
                $app->make(GetShowBrandsVoiceTool::class),
                $app->make(ResolveCustomerIdentityVoiceTool::class),
                $app->make(GetCustomerContextVoiceTool::class),
                $app->make(StartExtendedIdentitySearchVoiceTool::class),
                $app->make(GetExtendedIdentitySearchStatusVoiceTool::class),
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
