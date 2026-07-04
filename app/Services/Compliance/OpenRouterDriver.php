<?php

namespace App\Services\Compliance;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterDriver extends AbstractComplianceDriver
{
    protected function callModel(string $prompt): string
    {
        $apiKey = config('services.openrouter.api_key');
        $model = config('services.openrouter.model');
        $url = config('services.openrouter.url');

        $response = Http::timeout(30)
            ->retry(2, 200, throw: false)
            ->withHeader('Authorization', 'Bearer '.$apiKey)
            ->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('OpenRouterDriver: API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('OpenRouterDriver: API call failed: '.$response->body());
        }

        return $response->json('choices.0.message.content') ?? '';
    }
}
