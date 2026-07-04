<?php

namespace App\Services;

use App\Contracts\ComplianceDriver;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class TransactionProcessorService
{
    const FEED_KEY = 'sentinel:recent_transactions';

    const FEED_LENGTH = 50;

    const NAMESPACE = 'transactions';

    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly ThreatAnalysisService $analyzer,
        private readonly VectorCacheService $vectorCache,
        private readonly ComplianceDriver $driver,
    ) {}

    /**
     * Run the full compliance pipeline for a single transaction.
     *
     * Returns a summary array for callers that want to log or display results.
     * Safe to ignore in queued jobs — side effects (metrics, feed) always happen.
     *
     * Set $observe = false to skip metrics and feed recording (e.g. MCP / ad-hoc queries).
     * The vector cache is always written regardless, as it benefits all callers.
     *
     * risk_level/narrative/confidence/policy_refs are additive: they surface
     * the full ComplianceDriver::analyzeTransaction() grading that this
     * method previously collapsed into just a boolean is_threat. Existing
     * callers (WatchTransactions, StreamTransactionsJob, ProcessStreamJob,
     * AnalyzeTransaction MCP tool) only ever read source/is_threat/message/
     * elapsed_ms and are unaffected.
     *
     * @return array{source: string, is_threat: bool, message: string, elapsed_ms: float, risk_level: string, narrative: ?string, confidence: ?float, policy_refs: array}
     */
    public function process(array $data, bool $observe = true): array
    {
        $startTime = microtime(true);
        $txnId = $data['id'] ?? uniqid('txn_');
        $merchant = $data['merchant'] ?? $data['merchant_name'] ?? '?';
        $rawAmount = isset($data['amount']) ? (float) $data['amount'] : null;
        $amount = $rawAmount !== null ? number_format($rawAmount, 2) : '?';
        $currency = $data['currency'] ?? '';

        try {
            $fingerprint = $this->embedding->createTransactionFingerprint($data);
            $vector = $this->embedding->embed($fingerprint);
            $threshold = (float) config('services.upstash_vector.similarity_threshold');
            $results = $this->vectorCache->searchNamespace($vector, self::NAMESPACE, $threshold, 1);
            $cached = $results[0] ?? null;

            if ($cached !== null) {
                $cachedEpoch = $cached['metadata']['policy_epoch'] ?? null;
                $currentEpoch = Cache::get('sentinel_policy_epoch');

                if ($cachedEpoch !== $currentEpoch) {
                    \Illuminate\Support\Facades\Log::info('Vector cache stale: policy epoch mismatch — re-analyzing', [
                        'cached_epoch' => $cachedEpoch,
                        'current_epoch' => $currentEpoch,
                    ]);
                    $cached = null;
                }
            }

            if ($cached) {
                $analysis = $cached['metadata']['analysis'];
                $isThreat = $analysis['isThreat'];
                $message = $analysis['message'];
                // ?? fallbacks handle vectors cached before these fields existed.
                $riskLevel = $analysis['risk_level'] ?? ($isThreat ? 'high' : 'low');
                $narrative = $analysis['narrative'] ?? $message;
                $confidence = $analysis['confidence'] ?? null;
                $policyRefs = $analysis['policy_refs'] ?? [];

                if ($observe) {
                    if ($isThreat) {
                        Cache::increment('sentinel_metrics_threat_count');
                    }
                    $this->recordMetric('cache_hit', microtime(true) - $startTime);
                    $this->recordTransaction($txnId, $merchant, $amount, $currency, $isThreat, $message, 'cache_hit', $rawAmount);
                }

                return $this->summary('cache_hit', $isThreat, $message, $startTime, $riskLevel, $narrative, $confidence, $policyRefs);
            }

            // Cache miss — Tier 2: Gemini/OpenRouter analysis with policy RAG (ADR-0007)
            $aiResult = $this->driver->analyzeTransaction($data);
            $riskLevel = $aiResult['risk_level'] ?? 'unknown';
            $isThreat = $riskLevel !== 'low';
            $narrative = $aiResult['narrative'] ?: null;
            $confidence = $aiResult['confidence'] ?? null;
            $policyRefs = $aiResult['policy_refs'] ?? [];
            $message = $narrative ?: ($isThreat
                ? "Compliance risk detected at {$merchant} ({$riskLevel})"
                : "Layer 7 Clear: {$merchant} - OK");

            $this->vectorCache->upsertNamespace(
                "txn_{$txnId}",
                $vector,
                [
                    'analysis' => [
                        'isThreat' => $isThreat,
                        'message' => $message,
                        'threat_level' => $isThreat ? 'high' : 'low',
                        'risk_level' => $riskLevel,
                        'narrative' => $narrative,
                        'confidence' => $confidence,
                        'policy_refs' => $policyRefs,
                    ],
                    'timestamp' => now()->toIso8601String(),
                    'threat_level' => $isThreat ? 'high' : 'low',
                    'policy_epoch' => Cache::get('sentinel_policy_epoch'),
                ],
                self::NAMESPACE,
            );

            if ($observe) {
                $this->recordMetric('cache_miss', microtime(true) - $startTime);
            }
            $source = 'cache_miss';
        } catch (\Throwable) {
            // Embedding, vector cache, or AI analysis unavailable — fall back to rule-based Tier 3.
            // ThreatAnalysisService failure is intentionally uncaught and propagates.
            $result = $this->analyzer->analyze($data);
            $isThreat = $result->isThreat;
            $message = $result->message;
            // Tier 3 is rule-based, not graded — mirror the same high/low
            // convention used elsewhere so callers always get a risk_level.
            $riskLevel = $isThreat ? 'high' : 'low';
            $narrative = $message;
            $confidence = null;
            $policyRefs = [];
            if ($observe) {
                $this->recordMetric('fallback', microtime(true) - $startTime);
            }
            $source = 'fallback';
        }

        if ($observe) {
            if ($isThreat) {
                Cache::increment('sentinel_metrics_threat_count');
            }
            $this->recordTransaction($txnId, $merchant, $amount, $currency, $isThreat, $message, $source);
        }

        return $this->summary($source, $isThreat, $message, $startTime, $riskLevel, $narrative, $confidence, $policyRefs);
    }

    private function summary(
        string $source,
        bool $isThreat,
        string $message,
        float $startTime,
        string $riskLevel,
        ?string $narrative,
        ?float $confidence,
        array $policyRefs,
    ): array {
        return [
            'source' => $source,
            'is_threat' => $isThreat,
            'message' => $message,
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'risk_level' => $riskLevel,
            'narrative' => $narrative,
            'confidence' => $confidence,
            'policy_refs' => $policyRefs,
        ];
    }

    private function recordMetric(string $type, float $duration): void
    {
        Cache::increment("sentinel_metrics_{$type}_count");
        Cache::increment("sentinel_metrics_{$type}_time", (int) ($duration * 1000));
    }

    private function recordTransaction(
        string $txnId,
        string $merchant,
        string $amount,
        string $currency,
        bool $isThreat,
        string $message,
        string $source,
        ?float $rawAmount = null,
    ): void {
        $entry = json_encode([
            'id' => $txnId,
            'merchant' => $merchant,
            'amount' => $amount,
            'currency' => $currency,
            'is_threat' => $isThreat,
            'message' => $message,
            'source' => $source,
            'at' => now()->toIso8601String(),
        ]);

        Redis::executeRaw(['LPUSH', self::FEED_KEY, $entry]);
        Redis::executeRaw(['LTRIM', self::FEED_KEY, 0, self::FEED_LENGTH - 1]);

        Transaction::create([
            'txn_id' => $txnId,
            'merchant' => $merchant,
            'amount' => $rawAmount,
            'currency' => $currency ?: null,
            'is_threat' => $isThreat,
            'message' => $message,
            'source' => $source,
        ]);
    }
}
