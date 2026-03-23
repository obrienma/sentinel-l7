<?php

namespace App\Mcp\Tools;

use App\Services\EmbeddingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchPolicies extends Tool
{
    protected string $description = <<<'MARKDOWN'
        Search the compliance policy knowledge base for relevant regulatory rules.
        Use this to retrieve AML, BSA, HIPAA, or GDPR policy context by semantic similarity.
        Returns scored policy chunks with their text and metadata. Threshold: 0.70.
    MARKDOWN;

    public function handle(Request $request, EmbeddingService $embedding): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3',
            'limit' => 'sometimes|integer|min:1|max:10',
        ]);

        $vector  = $embedding->embed($validated['query']);
        $limit   = $validated['limit'] ?? 3;
        $baseUrl = config('services.upstash_vector.url');
        $token   = config('services.upstash_vector.token');

        $response = Http::withToken($token)
            ->timeout(5)
            ->retry(2, 150, throw: false)
            ->post("{$baseUrl}/namespaces/policies/query", [
                'vector'          => $vector,
                'topK'            => $limit,
                'includeMetadata' => true,
            ]);

        if (!$response->successful()) {
            Log::warning('MCP policy search failed', ['status' => $response->status()]);

            return Response::json(['policies' => [], 'error' => 'Policy search unavailable']);
        }

        $policies = collect($response->json('result') ?? [])
            ->filter(fn (array $r) => ($r['score'] ?? 0) >= 0.70)
            ->map(fn (array $r) => [
                'id'       => $r['id'] ?? null,
                'score'    => round($r['score'] ?? 0, 4),
                'metadata' => $r['metadata'] ?? [],
            ])
            ->values()
            ->all();

        return Response::json(['policies' => $policies, 'count' => count($policies)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Plain-language description of the transaction or compliance question to search against'),
            'limit' => $schema->integer()->nullable()->description('Maximum number of policy chunks to return (default 3, max 10)'),
        ];
    }
}
