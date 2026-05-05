<?php

namespace App\Services\Compliance;

use App\Contracts\ComplianceDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterDriver implements ComplianceDriver
{
    private const NARRATIVE_LENGTH_MIN = 150;

    private const QUALITY_WARNING_THRESHOLD = 1;

    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorCacheService $vectorCache,
    ) {}

    public function analyze(array $data): array
    {
        $query = $this->buildQueryText($data);
        $policyChunks = $this->fetchPolicyContext($query, $data);
        $prompt = $this->buildPrompt($data, $policyChunks);
        $raw = $this->callOpenRouter($prompt);
        $result = $this->parseResponse($raw);
        $this->logResponseQuality($result, $data);

        return $result;
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

    private function fetchPolicyContext(string $query, array $data = []): array
    {
        try {
            $domain = isset($data['domain']) && $data['domain'] !== null
                ? (string) $data['domain']
                : null;
            $filter = $domain !== null ? "domain = '{$domain}'" : null;

            $vector = $this->embedding->embed($query);
            $chunks = $this->vectorCache->searchNamespace($vector, 'policies', 0.70, 3, $filter);

            $scores = array_column($chunks, 'score');
            $meanScore = count($scores) > 0
                ? round(array_sum($scores) / count($scores), 4)
                : null;
            $underIndexed = $domain !== null && count($chunks) < 2;

            Log::info('OpenRouterDriver: policy RAG retrieval', [
                'domain' => $domain,
                'filter_used' => $filter !== null,
                'chunk_count' => count($chunks),
                'mean_score' => $meanScore,
                'under_indexed' => $underIndexed,
                'scores' => $scores,
            ]);

            if ($underIndexed) {
                Log::warning('OpenRouterDriver: under-indexed domain', [
                    'domain' => $domain,
                    'chunk_count' => count($chunks),
                    'mean_score' => $meanScore,
                    'source_id' => $data['source_id'] ?? null,
                ]);
            }

            return $chunks;
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
                ->map(fn ($c) => '- '.($c['metadata']['text'] ?? json_encode($c['metadata'])))
                ->implode("\n");

        return strtr(
            file_get_contents(base_path('prompts/compliance-audit-narrative.txt')),
            [
                '{status}' => $data['status'] ?? 'unknown',
                '{metric_value}' => $data['metric_value'] ?? 'unknown',
                '{anomaly_score}' => $data['anomaly_score'] ?? 'unknown',
                '{source_id}' => $data['source_id'] ?? 'unknown',
                '{emitted_at}' => $data['emitted_at'] ?? 'unknown',
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

    private function logResponseQuality(array $result, array $data): void
    {
        $hasPolicyRefs = ! empty($result['policy_refs']);
        $hasRiskLevel = ($result['risk_level'] ?? 'unknown') !== 'unknown';
        $narrativeLength = strlen((string) ($result['narrative'] ?? ''));
        $aboveLengthMin = $narrativeLength >= self::NARRATIVE_LENGTH_MIN;
        $confidence = (float) ($result['confidence'] ?? 0.0);
        $aboveConfidence = $confidence >= 0.6;

        $qualityScore = (int) $hasPolicyRefs
                      + (int) $hasRiskLevel
                      + (int) $aboveLengthMin
                      + (int) $aboveConfidence;

        $context = [
            'source_id' => $data['source_id'] ?? null,
            'domain' => $data['domain'] ?? null,
            'has_policy_refs' => $hasPolicyRefs,
            'has_risk_level' => $hasRiskLevel,
            'narrative_length' => $narrativeLength,
            'above_length_min' => $aboveLengthMin,
            'confidence' => $confidence,
            'quality_score' => $qualityScore,
        ];

        Log::info('OpenRouterDriver: response quality', $context);

        if ($qualityScore <= self::QUALITY_WARNING_THRESHOLD) {
            Log::warning('OpenRouterDriver: low quality score', $context);
        }
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
