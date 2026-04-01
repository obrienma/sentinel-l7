<?php

namespace App\Services\Compliance;

use App\Contracts\ComplianceDriver;

class OpenRouterDriver implements ComplianceDriver
{
    public function analyze(array $data): array
    {
        // TODO: implement — POST to https://openrouter.ai/api/v1/chat/completions
        // with Authorization: Bearer {OPENROUTER_API_KEY}
        throw new \RuntimeException('OpenRouterDriver is not yet implemented.');
    }
}
