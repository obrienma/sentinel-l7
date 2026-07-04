<?php

namespace App\Services\Compliance;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiDriver extends AbstractComplianceDriver
{
    protected function callModel(string $prompt): string
    {
        $apiKey = config('services.gemini.api_key');
        $url = config('services.gemini.flash_url');

        $response = Http::timeout(15)
            ->retry(2, 200, throw: false)
            ->post($url.'?key='.$apiKey, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('GeminiDriver: Flash API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('GeminiDriver: Flash API call failed: '.$response->body());
        }

        return $response->json('candidates.0.content.parts.0.text') ?? '';
    }
}
