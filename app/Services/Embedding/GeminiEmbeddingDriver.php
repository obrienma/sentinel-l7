<?php

namespace App\Services\Embedding;

use App\Contracts\EmbeddingDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiEmbeddingDriver implements EmbeddingDriver
{
    public function embed(string $text, string $task = self::TASK_DOCUMENT): array
    {
        $apiKey = config('services.gemini.api_key');
        $baseUrl = config('services.gemini.embedding_url')
            ?: 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->timeout(10)
            ->retry(3, 200, throw: false)
            ->post($baseUrl.'?key='.$apiKey, [
                'content' => [
                    'parts' => [
                        ['text' => $text],
                    ],
                ],
                'output_dimensionality' => 1536,
            ]);

        if (! $response->successful()) {
            Log::warning('Gemini embedding failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Gemini embedding failed: '.$response->body());
        }

        return $response->json('embedding.values');
    }
}
