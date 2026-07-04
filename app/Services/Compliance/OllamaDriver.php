<?php

namespace App\Services\Compliance;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaDriver extends AbstractComplianceDriver
{
    protected function callModel(string $prompt): string
    {
        $baseUrl = rtrim(config('services.ollama.url'), '/');
        $model = config('services.ollama.chat_model');
        $timeout = config('services.ollama.chat_timeout');

        $response = Http::timeout($timeout)
            ->retry(2, 200, throw: false)
            ->post($baseUrl.'/api/chat', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'format' => 'json',
                'stream' => false,
                // qwen3.5 is a hybrid reasoning model; without this it emits a
                // verbose `message.thinking` trace before answering, taking
                // ~20x longer for no gain here — content alone is unaffected.
                'think' => false,
            ]);

        if (! $response->successful()) {
            Log::warning('OllamaDriver: API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('OllamaDriver: API call failed: '.$response->body());
        }

        return $response->json('message.content') ?? '';
    }
}
