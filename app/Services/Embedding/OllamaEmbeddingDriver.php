<?php

namespace App\Services\Embedding;

use App\Contracts\EmbeddingDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaEmbeddingDriver implements EmbeddingDriver
{
    public function embed(string $text, string $task = self::TASK_DOCUMENT): array
    {
        $baseUrl = rtrim(config('services.ollama.url'), '/');
        $model = config('services.ollama.embedding_model');
        $timeout = config('services.ollama.timeout');

        $response = Http::timeout($timeout)
            ->retry(3, 200, throw: false)
            ->post($baseUrl.'/api/embeddings', [
                'model' => $model,
                'prompt' => $task.': '.$text,
            ]);

        if (! $response->successful()) {
            Log::warning('Ollama embedding failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Ollama embedding failed: '.$response->body());
        }

        return $response->json('embedding');
    }
}
