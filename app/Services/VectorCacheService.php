<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorCacheService
{
    protected string $baseUrl;

    protected string $token;

    public function __construct()
    {
        $this->baseUrl = config('services.upstash_vector.url');
        $this->token = config('services.upstash_vector.token');
    }

    /**
     * Search a specific namespace with an explicit threshold.
     * Returns all results above the threshold (not just the top match).
     *
     * @return array<int, array{id: string|null, score: float, metadata: array}>
     */
    public function searchNamespace(
        array $embedding,
        string $namespace,
        float $threshold,
        int $topK = 3,
        ?string $filter = null
    ): array {
        $payload = [
            'vector' => $embedding,
            'topK' => $topK,
            'includeMetadata' => true,
        ];

        if ($filter !== null) {
            $payload['filter'] = $filter;
        }

        $response = Http::withToken($this->token)
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/query/{$namespace}", $payload);

        if (! $response->successful()) {
            Log::warning('Vector namespace search failed', [
                'namespace' => $namespace,
                'status' => $response->status(),
            ]);

            return [];
        }

        return collect($response->json('result') ?? [])
            ->filter(fn (array $r) => ($r['score'] ?? 0) >= $threshold)
            ->map(fn (array $r) => [
                'id' => $r['id'] ?? null,
                'score' => round($r['score'] ?? 0, 4),
                'metadata' => $r['metadata'] ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * Upsert a vector into a specific namespace.
     */
    public function upsertNamespace(string $id, array $embedding, array $metadata, string $namespace): bool
    {
        $response = Http::withToken($this->token)
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/upsert/{$namespace}", [
                [
                    'id' => $id,
                    'vector' => $embedding,
                    'metadata' => $metadata,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Vector namespace upsert failed', [
                'namespace' => $namespace,
                'id' => $id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Delete a vector from a specific namespace.
     */
    public function deleteNamespace(string $id, string $namespace): bool
    {
        $response = Http::withToken($this->token)
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/delete/{$namespace}", [$id]);

        if (! $response->successful()) {
            Log::warning('Vector namespace delete failed', [
                'namespace' => $namespace,
                'id' => $id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
