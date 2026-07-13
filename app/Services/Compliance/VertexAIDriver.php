<?php

namespace App\Services\Compliance;

use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VertexAIDriver extends AbstractComplianceDriver
{
    // Vertex AI's Claude passthrough takes this in the request body instead
    // of a header — fixed protocol version, not a tunable value.
    private const ANTHROPIC_VERSION = 'vertex-2023-10-16';

    public function __construct(
        EmbeddingService $embedding,
        VectorCacheService $vectorCache,
        private readonly VertexAiTokenService $tokenService,
    ) {
        parent::__construct($embedding, $vectorCache);
    }

    protected function callModel(string $prompt): string
    {
        $projectId = config('services.vertexai.project_id');
        $region = config('services.vertexai.region');
        $model = config('services.vertexai.model');

        $url = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$projectId}"
             ."/locations/{$region}/publishers/anthropic/models/{$model}:rawPredict";

        $response = Http::timeout(config('services.vertexai.timeout'))
            ->retry(2, 200, throw: false)
            ->withToken($this->tokenService->fetchAccessToken())
            ->post($url, [
                'anthropic_version' => self::ANTHROPIC_VERSION,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => config('services.vertexai.max_tokens'),
                // Short JSON-classification/extraction task, not open-ended
                // reasoning — Sonnet 4.6 defaults to high-effort adaptive
                // thinking, which would add billed reasoning tokens with no
                // quality benefit here. Disabled per Anthropic's guidance
                // for this workload shape.
                'thinking' => ['type' => 'disabled'],
                'output_config' => ['effort' => 'low'],
            ]);

        if (! $response->successful()) {
            Log::warning('VertexAIDriver: rawPredict call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('VertexAIDriver: rawPredict call failed: '.$response->body());
        }

        return $response->json('content.0.text') ?? '';
    }
}
