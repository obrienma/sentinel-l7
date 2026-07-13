<?php

use App\Contracts\ComplianceDriver;
use App\Services\Compliance\GeminiDriver;
use App\Services\Compliance\OllamaDriver;
use App\Services\Compliance\OpenRouterDriver;
use App\Services\Compliance\VertexAIDriver;
use App\Services\ComplianceManager;

uses(Tests\TestCase::class);

it('resolves GeminiDriver when ai_driver is gemini', function () {
    config(['sentinel.ai_driver' => 'gemini']);

    expect(app(ComplianceDriver::class))->toBeInstanceOf(GeminiDriver::class);
});

it('resolves OpenRouterDriver when ai_driver is openrouter', function () {
    config(['sentinel.ai_driver' => 'openrouter']);

    expect(app(ComplianceDriver::class))->toBeInstanceOf(OpenRouterDriver::class);
});

it('resolves OllamaDriver when ai_driver is ollama', function () {
    config(['sentinel.ai_driver' => 'ollama']);

    expect(app(ComplianceDriver::class))->toBeInstanceOf(OllamaDriver::class);
});

it('resolves VertexAIDriver when ai_driver is vertexai', function () {
    config(['sentinel.ai_driver' => 'vertexai']);

    expect(app(ComplianceDriver::class))->toBeInstanceOf(VertexAIDriver::class);
});

it('throws when ai_driver names an unregistered driver', function () {
    config(['sentinel.ai_driver' => 'bogus']);

    expect(fn () => app(ComplianceManager::class)->driver())
        ->toThrow(\InvalidArgumentException::class);
});

it('defaults to ollama when SENTINEL_AI_DRIVER is unset (ADR-0027)', function () {
    // The already-booted config() repository has env('SENTINEL_AI_DRIVER', ...)
    // baked in from whatever this environment's real .env sets — re-`require`
    // the config file with the env var cleared to test the literal fallback.
    $original = getenv('SENTINEL_AI_DRIVER');

    putenv('SENTINEL_AI_DRIVER');
    unset($_ENV['SENTINEL_AI_DRIVER'], $_SERVER['SENTINEL_AI_DRIVER']);

    try {
        $config = require config_path('sentinel.php');
        expect($config['ai_driver'])->toBe('ollama');
    } finally {
        if ($original !== false) {
            putenv("SENTINEL_AI_DRIVER={$original}");
            $_ENV['SENTINEL_AI_DRIVER'] = $original;
            $_SERVER['SENTINEL_AI_DRIVER'] = $original;
        }
    }
});
