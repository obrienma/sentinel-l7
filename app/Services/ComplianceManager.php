<?php

namespace App\Services;

use App\Services\Compliance\GeminiDriver;
use App\Services\Compliance\OpenRouterDriver;
use Illuminate\Support\Manager;

class ComplianceManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('sentinel.ai_driver', 'gemini');
    }

    protected function createGeminiDriver(): GeminiDriver
    {
        return $this->container->make(GeminiDriver::class);
    }

    protected function createOpenrouterDriver(): OpenRouterDriver
    {
        return $this->container->make(OpenRouterDriver::class);
    }
}
