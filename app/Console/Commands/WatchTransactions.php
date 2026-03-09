<?php
namespace App\Console\Commands;

use App\Services\EmbeddingService;
use App\Services\ThreatAnalysisService;
use App\Services\TransactionStreamService;
use App\Services\VectorCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WatchTransactions extends Command
{
    protected $signature = 'sentinel:watch';
    protected $description = 'Monitor the L7 layer for suspicious activity';

    public function handle(
        TransactionStreamService $stream,
        ThreatAnalysisService $analyzer,
        EmbeddingService $embedding,
        VectorCacheService $vectorCache,
    ): void {
        $this->info('Sentinel-L7: Watcher initialized...');

        while (true) {
            foreach ($stream->read('$') as $streamMsg) {
                $startTime = microtime(true);
                $data = json_decode($streamMsg[1][1], true);

                $txnId   = $data['id'] ?? '?';
                $merchant = $data['merchant'] ?? $data['merchant_name'] ?? '?';
                $amount  = isset($data['amount']) ? number_format((float)$data['amount'], 2) : '?';
                $currency = $data['currency'] ?? '';

                $this->line('');
                $this->line("<fg=blue>──── TXN {$txnId}</>");
                $this->line("<fg=blue>     {$merchant} | {$currency} {$amount}</>");

                try {
                    // 1. Create semantic fingerprint and generate embedding
                    $fingerprint = $embedding->createTransactionFingerprint($data);
                    $vector = $embedding->embed($fingerprint);

                    // 2. Search for similar cached analysis
                    $cached = $vectorCache->search($vector);

                    if ($cached) {
                        // Cache hit — reuse stored analysis
                        $cachedAnalysis = $cached['metadata']['analysis'];
                        $score = isset($cached['score']) ? round($cached['score'] * 100, 1) . '%' : 'n/a';
                        $matchedId = $cached['id'] ?? 'unknown';
                        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                        $this->info("✅ Cache hit [{$elapsed}ms] — matched {$matchedId} (similarity: {$score})");

                        if ($cachedAnalysis['isThreat']) {
                            $this->error("!!! THREAT DETECTED (cached): {$cachedAnalysis['message']}");
                            // todo: Trigger an Inertia Event or Broadcast to dashboard
                        } else {
                            $this->line($cachedAnalysis['message']);
                        }

                        $this->recordMetric('cache_hit', microtime(true) - $startTime);
                        continue;
                    }

                    // Cache miss — run full analysis and store result
                    $result = $analyzer->analyze($data);
                    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                    $this->warn("❌ Cache miss [{$elapsed}ms] — fingerprint: {$fingerprint}");

                    $txnId = $data['id'] ?? uniqid('txn_');
                    $vectorCache->upsert(
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

                    $this->recordMetric('cache_miss', microtime(true) - $startTime);
                } catch (\Throwable $e) {
                    // Embedding or vector cache unavailable — fall back to direct analysis
                    $this->warn('⚠️  Vector path failed (' . $e->getMessage() . '), falling back to direct analysis.');
                    $result = $analyzer->analyze($data);
                    $this->recordMetric('fallback', microtime(true) - $startTime);
                }

                if (isset($result)) {
                    if ($result->isThreat) {
                        $this->error("!!! THREAT DETECTED: {$result->message}");
                        // todo: Trigger an Inertia Event or Broadcast to dashboard
                    } else {
                        $this->line($result->message);
                    }
                    unset($result);
                }
            }
        }
    }

    private function recordMetric(string $type, float $duration): void
    {
        Cache::increment("sentinel_metrics_{$type}_count");
        Cache::increment("sentinel_metrics_{$type}_time", (int) ($duration * 1000));
    }
}
