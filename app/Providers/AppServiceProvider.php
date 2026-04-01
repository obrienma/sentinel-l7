<?php

namespace App\Providers;

use App\Contracts\ComplianceDriver;
use App\Services\AxiomProcessorService;
use App\Services\AxiomStreamService;
use App\Services\ComplianceManager;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ComplianceManager::class, fn ($app) => new ComplianceManager($app));
        $this->app->bind(ComplianceDriver::class, fn ($app) => $app->make(ComplianceManager::class)->driver());

        $this->app->singleton(AxiomStreamService::class);
        $this->app->singleton(AxiomProcessorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production environments
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
