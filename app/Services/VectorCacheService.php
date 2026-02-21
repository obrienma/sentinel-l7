<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class VectorCacheService
{
    protected string $baseUrl;
    protected string $token;
    protected float $threshold;

    public function __construct()
    {
        $this->baseUrl = config('services.upstash_vector.url');
        $this->token = config('services.upstash_vector.token');
        $this->threshold = config('services.upstash_vector.similarity_threshold');
    }

    public function search(array $embedding, int $topK = 3): ?array
    {
        $response = Http::withToken($this->token)
            ->post("{$this->baseUrl}/query", [
                'vector' => $embedding,
                'topK' => $topK,
                'includeMetadata' => true,
            ]);

        if (!$response->successful()) {
            return null;
        }

        $results = $response->json('result');

        // Check if best match exceeds threshold
        if (empty($results) || !isset($results[0]['score']) || $results[0]['score'] < $this->threshold) {
            return null;
        }

        return $results[0]; // Return best match
    }

    public function upsert(string $id, array $embedding, array $metadata): bool
    {
        $response = Http::withToken($this->token)
            ->post("{$this->baseUrl}/upsert", [
                [
                    'id' => $id,
                    'vector' => $embedding,
                    'metadata' => $metadata,
                ]
            ]);

        return $response->successful();
    }
}
