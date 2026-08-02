<?php

namespace App\Services\Compliance;

use App\Contracts\ComplianceDriver;
use App\Contracts\EmbeddingDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class AbstractComplianceDriver implements ComplianceDriver
{
    protected const NARRATIVE_LENGTH_MIN = 150;

    protected const QUALITY_WARNING_THRESHOLD = 1;

    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorCacheService $vectorCache,
    ) {}

    abstract protected function callModel(string $prompt): string;

    public function analyze(array $data): array
    {
        $query = $this->buildQueryText($data);
        $policyChunks = $this->fetchPolicyContext($query, $data);
        $prompt = $this->buildPrompt($data, $policyChunks);
        $raw = $this->callModel($prompt);
        $result = $this->parseResponse($raw);
        $result = $this->guardPolicyRefsAgainstEmptyRetrieval($result, $policyChunks, $data);
        $this->logResponseQuality($result, $data);

        return $result;
    }

    public function analyzeTransaction(array $data): array
    {
        $query = $this->buildTransactionQueryText($data);
        $policyChunks = $this->fetchPolicyContext($query, $data);
        $prompt = $this->buildTransactionPrompt($data, $policyChunks);
        $raw = $this->callModel($prompt);
        $result = $this->parseResponse($raw);
        $result = $this->guardPolicyRefsAgainstEmptyRetrieval($result, $policyChunks, $data);
        $this->logResponseQuality($result, $data);

        return $result;
    }

    /**
     * ADR-0034: `chunk_count === 0` means no policy chunk cleared the retrieval
     * threshold, so any non-empty `policy_refs` the model returned is fabricated
     * — the model does not reliably honor "no context retrieved" prompt wording.
     *
     * Scope note: this only guards the structured `policy_refs` field. It does
     * not sanitize `result['narrative']` — free-text prose that also reaches
     * `compliance_events.audit_narrative` (AxiomProcessorService) and the cached
     * transaction analysis (TransactionProcessorService) unmodified — and it does
     * not verify that a `policy_refs` entry corresponds to an actually-retrieved
     * chunk when `chunk_count > 0`. Both are open follow-ups (see TODO in
     * CLAUDE.md) requiring their own design decision rather than a blind fix.
     */
    private function guardPolicyRefsAgainstEmptyRetrieval(array $result, array $policyChunks, array $data): array
    {
        if (count($policyChunks) === 0 && ! empty($result['policy_refs'])) {
            Log::warning(class_basename(static::class).': policy_refs fabricated on empty retrieval', [
                ...$this->auditContext($data),
                'model_asserted_refs' => $result['policy_refs'],
            ]);

            $result['policy_refs'] = [];
        }

        return $result;
    }

    /**
     * Shared source_id/domain extraction for log context — used by the
     * fabrication guard, the under-indexed domain warning, and the response
     * quality log so the three stay consistent if the identifier fallback
     * logic ever changes.
     */
    private function auditContext(array $data): array
    {
        return [
            'source_id' => $data['source_id'] ?? null,
            'domain' => $data['domain'] ?? null,
        ];
    }

    private function buildTransactionQueryText(array $data): string
    {
        $merchant = $data['merchant'] ?? $data['merchant_name'] ?? 'an unspecified merchant';
        $amount = $data['amount'] ?? 'an unspecified amount';
        $currency = $data['currency'] ?? '';

        return 'What compliance obligations, reporting requirements, and regulatory thresholds apply '
             ."to a {$currency} {$amount} transaction at {$merchant}?";
    }

    private function buildTransactionPrompt(array $data, array $policyChunks): string
    {
        $policyText = empty($policyChunks)
            ? 'No specific policy context retrieved.'
            : collect($policyChunks)
                ->map(fn ($c) => '- '.($c['metadata']['text'] ?? json_encode($c['metadata'])))
                ->implode("\n");

        return strtr(
            file_get_contents(base_path('prompts/transaction-compliance-analysis.txt')),
            [
                '{merchant}' => $data['merchant'] ?? $data['merchant_name'] ?? 'unknown',
                '{amount}' => $data['amount'] ?? 'unknown',
                '{currency}' => $data['currency'] ?? 'unknown',
                '{policy_context}' => $policyText,
            ]
        );
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

            $vector = $this->embedding->embed($query, EmbeddingDriver::TASK_QUERY);
            $chunks = $this->vectorCache->searchNamespace($vector, 'policies', 0.70, 3, $filter);

            $scores = array_column($chunks, 'score');
            $meanScore = count($scores) > 0
                ? round(array_sum($scores) / count($scores), 4)
                : null;
            $underIndexed = $domain !== null && count($chunks) < 2;

            Log::info(class_basename(static::class).': policy RAG retrieval', [
                'domain' => $domain,
                'filter_used' => $filter !== null,
                'chunk_count' => count($chunks),
                'mean_score' => $meanScore,
                'under_indexed' => $underIndexed,
                'scores' => $scores,
            ]);

            if ($underIndexed) {
                Log::warning(class_basename(static::class).': under-indexed domain', [
                    'domain' => $domain,
                    'chunk_count' => count($chunks),
                    'mean_score' => $meanScore,
                    'source_id' => $data['source_id'] ?? null,
                ]);
            }

            return $chunks;
        } catch (\Throwable $e) {
            Log::warning(class_basename(static::class).': policy RAG failed, proceeding without context', [
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
            ...$this->auditContext($data),
            'has_policy_refs' => $hasPolicyRefs,
            'has_risk_level' => $hasRiskLevel,
            'narrative_length' => $narrativeLength,
            'above_length_min' => $aboveLengthMin,
            'confidence' => $confidence,
            'quality_score' => $qualityScore,
        ];

        Log::info(class_basename(static::class).': response quality', $context);

        if ($qualityScore <= self::QUALITY_WARNING_THRESHOLD) {
            Log::warning(class_basename(static::class).': low quality score', $context);
            Cache::increment('sentinel_metrics_low_quality_count');
        }
    }

    private function parseResponse(string $raw): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean);

        $decoded = json_decode(trim($clean), true);

        if (! is_array($decoded) || ! isset($decoded['narrative'])) {
            Log::warning(class_basename(static::class).': unexpected response shape', ['raw' => $raw]);

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
