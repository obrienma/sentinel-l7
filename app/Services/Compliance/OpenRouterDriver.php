<?php

namespace App\Services\Compliance;

use App\Contracts\ComplianceDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterDriver implements ComplianceDriver
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorCacheService $vectorCache,
    ) {}

    public function analyze(array $data): array
    {
        $query = $this->buildQueryText($data);
        $policyChunks = $this->fetchPolicyContext($query);
        $prompt = $this->buildPrompt($data, $policyChunks);
        $raw = $this->callOpenRouter($prompt);

        return $this->parseResponse($raw);
    }

    private function buildQueryText(array $data): string
    {
        $status = $data['status'] ?? 'unknown';
        $score = (float) ($data['anomaly_score'] ?? 0.0);

        $severity = match (true) {
            $score >= 0.90 => 'critical severity requiring immediate escalation and reporting',
            $score >= 0.80 => 'high severity requiring compliance review and possible regulatory notification',
            $score >= 0.60 => 'moderate severity requiring monitoring and documentation',
            default => 'low severity for audit logging',
        };

        return 'What compliance obligations, reporting requirements, and regulatory thresholds apply '
             ."to a {$status} anomaly event of {$severity}?";
    }

    private function fetchPolicyContext(string $query): array
    {
        try {
            $vector = $this->embedding->embed($query);

            return $this->vectorCache->searchNamespace($vector, 'policies', 0.70, 3);
        } catch (\Throwable $e) {
            Log::warning('OpenRouterDriver: policy RAG failed, proceeding without context', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function buildPrompt(array $data, array $policyChunks): string
    {
        $policyText = empty($policyChunks)
            ? 'No specific policy context retrieved.'
            : collect($policyChunks)
                ->map(fn ($c) => '- ' . ($c['metadata']['text'] ?? json_encode($c['metadata'])))
                ->implode("\n");

        return strtr(
            file_get_contents(base_path('prompts/compliance-audit-narrative.txt')),
            [
                '{status}'         => $data['status']        ?? 'unknown',
                '{metric_value}'   => $data['metric_value']  ?? 'unknown',
                '{anomaly_score}'  => $data['anomaly_score'] ?? 'unknown',
                '{source_id}'      => $data['source_id']     ?? 'unknown',
                '{emitted_at}'     => $data['emitted_at']    ?? 'unknown',
                '{policy_context}' => $policyText,
            ]
        );
    }

    private function callOpenRouter(string $prompt): string
    {
        $apiKey = config('services.openrouter.api_key');
        $model = config('services.openrouter.model');
        $url = config('services.openrouter.url');

        $response = Http::timeout(30)
            ->retry(2, 200, throw: false)
            ->withHeader('Authorization', 'Bearer '.$apiKey)
            ->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('OpenRouterDriver: API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('OpenRouterDriver: API call failed: '.$response->body());
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function parseResponse(string $raw): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean);

        $decoded = json_decode(trim($clean), true);

        if (! is_array($decoded) || ! isset($decoded['narrative'])) {
            Log::warning('OpenRouterDriver: unexpected response shape', ['raw' => $raw]);

            return [
                'narrative' => null,
                'risk_level' => 'unknown',
                'policy_refs' => [],
                'confidence' => 0.0,
            ];
        }

        return [
            'narrative' => (string) ($decoded['narrative'] ?? ''),
            'risk_level' => (string) ($decoded['risk_level'] ?? 'unknown'),
            'policy_refs' => (array) ($decoded['policy_refs'] ?? []),
            'confidence' => (float) ($decoded['confidence'] ?? 0.0),
        ];
    }
}
