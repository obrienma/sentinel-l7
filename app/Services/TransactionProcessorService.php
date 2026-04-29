<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class TransactionProcessorService
{
    const FEED_KEY    = 'sentinel:recent_transactions';
    const FEED_LENGTH = 50;

    public function __construct(
        private readonly EmbeddingService      $embedding,
        private readonly ThreatAnalysisService $analyzer,
        private readonly VectorCacheService    $vectorCache,
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
     * @return array{source: string, is_threat: bool, message: string, elapsed_ms: float}
     */
    public function process(array $data, bool $observe = true): array
    {
        $startTime = microtime(true);
        $txnId     = $data['id']       ?? uniqid('txn_');
        $merchant  = $data['merchant'] ?? $data['merchant_name'] ?? '?';
        $rawAmount = isset($data['amount']) ? (float) $data['amount'] : null;
        $amount    = $rawAmount !== null ? number_format($rawAmount, 2) : '?';
        $currency  = $data['currency'] ?? '';

        try {
            $fingerprint = $this->embedding->createTransactionFingerprint($data);
            $vector      = $this->embedding->embed($fingerprint);
            $cached      = $this->vectorCache->search($vector);

            if ($cached) {
                $analysis = $cached['metadata']['analysis'];
                $isThreat = $analysis['isThreat'];
                $message  = $analysis['message'];

                if ($observe) {
                    if ($isThreat) {
                        Cache::increment('sentinel_metrics_threat_count');
                    }
                    $this->recordMetric('cache_hit', microtime(true) - $startTime);
                    $this->recordTransaction($txnId, $merchant, $amount, $currency, $isThreat, $message, 'cache_hit', $rawAmount);
                }

                return $this->summary('cache_hit', $isThreat, $message, $startTime);
            }

            // Cache miss — full analysis then store result
            $result = $this->analyzer->analyze($data);

            $this->vectorCache->upsert(
                "txn_{$txnId}",
                $vector,
                [
                    'analysis' => [
                        'isThreat'     => $result->isThreat,
                        'message'      => $result->message,
                        'threat_level' => $result->isThreat ? 'high' : 'low',
                    ],
                    'timestamp'    => now()->toIso8601String(),
                    'threat_level' => $result->isThreat ? 'high' : 'low',
                ]
            );

            if ($observe) {
                $this->recordMetric('cache_miss', microtime(true) - $startTime);
            }
            $source = 'cache_miss';
        } catch (\Throwable) {
            // Embedding or vector cache unavailable — fall back to direct analysis.
            // ThreatAnalysisService failure is intentionally uncaught and propagates.
            $result = $this->analyzer->analyze($data);
            if ($observe) {
                $this->recordMetric('fallback', microtime(true) - $startTime);
            }
            $source = 'fallback';
        }

        $isThreat = $result->isThreat;
        $message  = $result->message;

        if ($observe) {
            if ($isThreat) {
                Cache::increment('sentinel_metrics_threat_count');
            }
            $this->recordTransaction($txnId, $merchant, $amount, $currency, $isThreat, $message, $source);
        }

        return $this->summary($source, $isThreat, $message, $startTime);
    }

    private function summary(string $source, bool $isThreat, string $message, float $startTime): array
    {
        return [
            'source'     => $source,
            'is_threat'  => $isThreat,
            'message'    => $message,
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2),
        ];
    }

    private function recordMetric(string $type, float $duration): void
    {
        Cache::increment("sentinel_metrics_{$type}_count");
        Cache::increment("sentinel_metrics_{$type}_time", (int) ($duration * 1000));
    }

    private function recordTransaction(
        string  $txnId,
        string  $merchant,
        string  $amount,
        string  $currency,
        bool    $isThreat,
        string  $message,
        string  $source,
        ?float  $rawAmount = null,
    ): void {
        $entry = json_encode([
            'id'        => $txnId,
            'merchant'  => $merchant,
            'amount'    => $amount,
            'currency'  => $currency,
            'is_threat' => $isThreat,
            'message'   => $message,
            'source'    => $source,
            'at'        => now()->toIso8601String(),
        ]);

        Redis::executeRaw(['LPUSH', self::FEED_KEY, $entry]);
        Redis::executeRaw(['LTRIM', self::FEED_KEY, 0, self::FEED_LENGTH - 1]);

        Transaction::create([
            'txn_id'    => $txnId,
            'merchant'  => $merchant,
            'amount'    => $rawAmount,
            'currency'  => $currency ?: null,
            'is_threat' => $isThreat,
            'message'   => $message,
            'source'    => $source,
        ]);
    }
}
