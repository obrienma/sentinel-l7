<?php

namespace App\Providers;

use App\Contracts\ComplianceDriver;
use App\Contracts\EmbeddingDriver;
use App\Services\AxiomProcessorService;
use App\Services\AxiomStreamService;
use App\Services\ComplianceManager;
use App\Services\EmbeddingManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->singleton(EmbeddingManager::class, fn ($app) => new EmbeddingManager($app));
        $this->app->bind(EmbeddingDriver::class, fn ($app) => $app->make(EmbeddingManager::class)->driver());

        $this->app->singleton(AxiomStreamService::class);
        $this->app->singleton(AxiomProcessorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('sentinel.rate_limits.login.attempts'))->by($request->ip())
        );

        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(config('sentinel.rate_limits.signup.attempts'))->by($request->ip())
        );

        // Per-user limit to protect Gemini embedding + AI quota
        RateLimiter::for('ai-stream', fn (Request $request) => Limit::perMinute(config('sentinel.rate_limits.ai_stream.attempts'))->by($request->user()?->id ?: $request->ip())
        );
    }
}
