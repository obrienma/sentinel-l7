<?php

namespace App\Mcp\Tools;

use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

    public function handle(Request $request, EmbeddingService $embedding, VectorCacheService $vectorCache): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3',
            'limit' => 'sometimes|integer|min:1|max:10',
        ]);

        $vector   = $embedding->embed($validated['query']);
        $limit    = $validated['limit'] ?? 3;
        $policies = $vectorCache->searchNamespace($vector, 'policies', 0.70, $limit);

        if ($policies === [] && $vector === null) {
            return Response::json(['policies' => [], 'error' => 'Policy search unavailable', 'count' => 0]);
        }

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
