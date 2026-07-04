<?php

use App\Contracts\ComplianceDriver;
use App\Services\Compliance\GeminiDriver;
use App\Services\Compliance\OllamaDriver;
use App\Services\Compliance\OpenRouterDriver;
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

it('throws when ai_driver names an unregistered driver', function () {
    config(['sentinel.ai_driver' => 'bogus']);

    expect(fn () => app(ComplianceManager::class)->driver())
        ->toThrow(\InvalidArgumentException::class);
});
