<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/query", [
                'vector' => $embedding,
                'topK' => $topK,
                'includeMetadata' => true,
            ]);

        if (!$response->successful()) {
            Log::warning('Vector cache search failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
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
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/upsert", [
                [
                    'id' => $id,
                    'vector' => $embedding,
                    'metadata' => $metadata,
                ]
            ]);

        if (!$response->successful()) {
            Log::warning('Vector cache upsert failed', [
                'id'     => $id,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Search a specific namespace with an explicit threshold.
     * Returns all results above the threshold (not just the top match).
     *
     * @return array<int, array{id: string|null, score: float, metadata: array}>
     */
    public function searchNamespace(array $embedding, string $namespace, float $threshold, int $topK = 3): array
    {
        $response = Http::withToken($this->token)
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/namespaces/{$namespace}/query", [
                'vector'          => $embedding,
                'topK'            => $topK,
                'includeMetadata' => true,
            ]);

        if (!$response->successful()) {
            Log::warning('Vector namespace search failed', [
                'namespace' => $namespace,
                'status'    => $response->status(),
            ]);
            return [];
        }

        return collect($response->json('result') ?? [])
            ->filter(fn (array $r) => ($r['score'] ?? 0) >= $threshold)
            ->map(fn (array $r) => [
                'id'       => $r['id'] ?? null,
                'score'    => round($r['score'] ?? 0, 4),
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
            ->post("{$this->baseUrl}/namespaces/{$namespace}/upsert", [
                [
                    'id'       => $id,
                    'vector'   => $embedding,
                    'metadata' => $metadata,
                ]
            ]);

        if (!$response->successful()) {
            Log::warning('Vector namespace upsert failed', [
                'namespace' => $namespace,
                'id'        => $id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    public function delete(string $id): bool
    {
        $response = Http::withToken($this->token)
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$this->baseUrl}/delete", [$id]);

        if (!$response->successful()) {
            Log::warning('Vector cache delete failed', [
                'id'     => $id,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;
        }

        return true;
    }
}
