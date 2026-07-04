<?php

use App\Contracts\ComplianceDriver;
use App\Services\EmbeddingService;
use App\Services\ThreatAnalysisService;
use App\Services\ThreatResult;
use App\Services\TransactionStreamService;
use App\Services\VectorCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis as LRedis;

uses(Tests\TestCase::class, RefreshDatabase::class);

// Default Redis stub — prevents real Upstash connections in tests that don't
// need to inspect the feed payload. Feed tests override with their own expectations.
beforeEach(fn () => LRedis::shouldReceive('executeRaw')->andReturn(1)->byDefault());

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * A single fake stream message in the format WatchTransactions expects:
 *   $streamMsg[1][1] => JSON-encoded transaction
 */
function fakeStreamMessage(array $overrides = []): array
{
    $data = array_merge([
        'id' => 'txn-test-1',
        'merchant' => 'Starbucks',
        'merchant_name' => 'Starbucks',
        'amount' => 12.50,
        'currency' => 'USD',
        'type' => 'purchase',
        'category' => 'coffee',
        'timestamp' => '2026-01-01T09:00:00+00:00',
    ], $overrides);

    return ['1-0', ['data', json_encode($data)]];
}

/**
 * Bind a mock stream that serves one message via readGroup then stops the
 * infinite loop by throwing on the second iteration. autoClaim returns empty
 * (no orphans); ensureConsumerGroup is a no-op; ack is satisfied.
 */
function mockStreamWithOneMessage(array $messageOverrides = []): \Mockery\MockInterface
{
    $calls = 0;

    $mock = Mockery::mock(TransactionStreamService::class);
    $mock->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $mock->shouldReceive('autoClaim')->andReturn([]);
    $mock->shouldReceive('ack')->andReturnNull();
    $mock->shouldReceive('pendingCount')->andReturn(0);
    $mock->shouldReceive('writeLagKey')->andReturnNull();
    $mock->shouldReceive('readGroup')
        ->andReturnUsing(function () use (&$calls, $messageOverrides) {
            $calls++;
            if ($calls === 1) {
                return ['messages' => [fakeStreamMessage($messageOverrides)]];
            }
            throw new \RuntimeException('__test_stop__');
        });

    return $mock;
}

/**
 * Mock the Tier 2 AI driver for a successful cache-miss analysis.
 */
function mockDriverSuccess(string $riskLevel, string $narrative): ComplianceDriver
{
    $m = Mockery::mock(ComplianceDriver::class);
    $m->shouldReceive('analyzeTransaction')->andReturn([
        'narrative' => $narrative,
        'risk_level' => $riskLevel,
        'policy_refs' => [],
        'confidence' => 0.9,
    ]);

    return $m;
}

/**
 * Mock the Tier 2 AI driver throwing (routes to Tier 3 rule-based fallback).
 */
function mockDriverFails(): ComplianceDriver
{
    $m = Mockery::mock(ComplianceDriver::class);
    $m->shouldReceive('analyzeTransaction')->andThrow(new \RuntimeException('Gemini Flash unavailable'));

    return $m;
}

/**
 * Mock the Tier 2 AI driver for paths where it must not be called
 * (cache hit, or embedding/vector failure before the AI call is reached).
 */
function mockDriverUnused(): ComplianceDriver
{
    $m = Mockery::mock(ComplianceDriver::class);
    $m->shouldNotReceive('analyzeTransaction');

    return $m;
}

/**
 * Run sentinel:watch, catching the loop-termination exception.
 * Any other exception is re-thrown so real errors still fail tests.
 */
function runWatcher(\Tests\TestCase $test): void
{
    try {
        $test->artisan('sentinel:watch');
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== '__test_stop__') {
            throw $e;
        }
    }
}

// ─── Cache hit path ──────────────────────────────────────────────────────────

it('skips the analyzer on a cache hit', function () {
    $cachedResult = [
        'id' => 'txn_old_abc',
        'score' => 0.97,
        'metadata' => [
            'analysis' => ['isThreat' => false, 'message' => 'Layer 7 Clear: Starbucks - OK'],
        ],
    ];

    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->once()->andReturn('Amount: 12.50 USD | ...');
    $embedding->shouldReceive('embed')->once()->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->once()->with($fakeVector, 'transactions', Mockery::any(), 1)->andReturn([$cachedResult]);
    $vectorCache->shouldNotReceive('upsertNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_hit_count'))->toBe(1);
    Mockery::close();
});

it('increments the cache_hit metric on a hit', function () {
    $fakeVector = array_fill(0, 1536, 0.2);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([[
        'id' => 'txn_old',
        'score' => 0.98,
        'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'OK']],
    ]]);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_hit_count'))->toBe(1);
});

// ─── Cache miss path ─────────────────────────────────────────────────────────

it('calls the AI driver and upserts the result on a cache miss', function () {
    $fakeVector = array_fill(0, 1536, 0.3);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->once()->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->once()->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->once()->with($fakeVector, 'transactions', Mockery::any(), 1)->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')
        ->once()
        ->with(
            Mockery::on(fn ($id) => str_starts_with($id, 'txn_')),
            $fakeVector,
            Mockery::on(fn ($meta) => isset($meta['analysis']['isThreat']) && isset($meta['threat_level'])),
            'transactions'
        );

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Starbucks - OK'));

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
    Mockery::close();
});

it('increments the cache_miss metric on a miss', function () {
    $fakeVector = array_fill(0, 1536, 0.4);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Starbucks - OK'));

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
});

it('upserts threat_level as "high" when the result is a threat', function () {
    $fakeVector = array_fill(0, 1536, 0.5);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')
        ->once()
        ->with(
            Mockery::any(),
            $fakeVector,
            Mockery::on(fn ($meta) => $meta['threat_level'] === 'high' && $meta['analysis']['isThreat'] === true),
            'transactions'
        );

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage(['amount' => 500.00, 'merchant' => 'BigBank']));
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('high', 'High value transaction at BigBank ($500.00)'));

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
    Mockery::close();
});

// ─── Fallback path ───────────────────────────────────────────────────────────

it('falls back to direct analysis when the embedding call fails', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andThrow(new \RuntimeException('Gemini embedding failed: quota exceeded'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');
    $vectorCache->shouldNotReceive('upsertNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->once()
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});

it('increments the fallback metric when the vector path throws', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andThrow(new \RuntimeException('network error'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
});

it('falls back when the vector search itself throws', function () {
    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andThrow(new \RuntimeException('Upstash timeout'));
    $vectorCache->shouldNotReceive('upsertNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->once()
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});

// ─── Edge cases ──────────────────────────────────────────────────────────────

it('displays a cached threat result on cache hit', function () {
    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([[
        'id' => 'txn_threat_cached',
        'score' => 0.99,
        'metadata' => ['analysis' => ['isThreat' => true, 'message' => 'THREAT: Suspicious deposit']],
    ]]);
    $vectorCache->shouldNotReceive('upsertNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_hit_count'))->toBe(1);
    Mockery::close();
});

it('falls back when fingerprint creation throws', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')
        ->andThrow(new \RuntimeException('Bad transaction data'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');
    $vectorCache->shouldNotReceive('upsertNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->once()
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});

it('falls back when upsert throws during cache miss', function () {
    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')->andThrow(new \RuntimeException('Upstash write error'));

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Starbucks - OK'));

    runWatcher($this);

    // The upsert throw is caught by the try/catch, so fallback metric is recorded
    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});

it('handles transactions with missing optional fields gracefully', function () {
    $fakeVector = array_fill(0, 1536, 0.1);

    // Transaction with only 'id' and 'amount' — no merchant_name, no currency
    $minimalMessage = fakeStreamMessage([
        'id' => 'txn-minimal',
        'merchant' => 'Unknown',
        'merchant_name' => null,
        'amount' => 5.00,
        'currency' => null,
    ]);

    $calls = 0;
    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('autoClaim')->andReturn([]);
    $stream->shouldReceive('ack')->andReturnNull();
    $stream->shouldReceive('pendingCount')->andReturn(0);
    $stream->shouldReceive('writeLagKey')->andReturnNull();
    $stream->shouldReceive('readGroup')->andReturnUsing(function () use (&$calls, $minimalMessage) {
        $calls++;
        if ($calls === 1) {
            return ['messages' => [$minimalMessage]];
        }
        throw new \RuntimeException('__test_stop__');
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, $stream);
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Unknown - OK'));

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
    Mockery::close();
});

// ─── Transaction feed (Redis list) ───────────────────────────────────────────

it('pushes a transaction entry to the redis feed on a cache hit', function () {
    $fakeVector = array_fill(0, 1536, 0.1);
    $lpushPayload = null;

    LRedis::shouldReceive('executeRaw')
        ->with(Mockery::on(function ($args) use (&$lpushPayload) {
            if ($args[0] === 'LPUSH') {
                $lpushPayload = json_decode($args[2], true);

                return true;
            }

            return true; // allow LTRIM through
        }))
        ->twice(); // LPUSH + LTRIM

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([[
        'id' => 'txn_old',
        'score' => 0.97,
        'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'Layer 7 Clear: Starbucks - OK']],
    ]]);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect($lpushPayload)->not->toBeNull()
        ->and($lpushPayload['merchant'])->toBe('Starbucks')
        ->and($lpushPayload['is_threat'])->toBeFalse()
        ->and($lpushPayload['source'])->toBe('cache_hit')
        ->and($lpushPayload)->toHaveKeys(['id', 'merchant', 'amount', 'currency', 'is_threat', 'message', 'source', 'at']);
    Mockery::close();
});

it('records is_threat true in the feed for a cached threat', function () {
    $fakeVector = array_fill(0, 1536, 0.1);
    $lpushPayload = null;

    LRedis::shouldReceive('executeRaw')
        ->with(Mockery::on(function ($args) use (&$lpushPayload) {
            if ($args[0] === 'LPUSH') {
                $lpushPayload = json_decode($args[2], true);
            }

            return true;
        }))
        ->twice();

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([[
        'id' => 'txn_threat',
        'score' => 0.99,
        'metadata' => ['analysis' => ['isThreat' => true, 'message' => 'High value transaction']],
    ]]);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage(['amount' => 500.00, 'merchant' => 'BigBank']));
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect($lpushPayload['is_threat'])->toBeTrue()
        ->and($lpushPayload['source'])->toBe('cache_hit')
        ->and($lpushPayload['merchant'])->toBe('BigBank');
    Mockery::close();
});

it('pushes a transaction entry to the redis feed on a cache miss', function () {
    $fakeVector = array_fill(0, 1536, 0.1);
    $lpushPayload = null;

    LRedis::shouldReceive('executeRaw')
        ->with(Mockery::on(function ($args) use (&$lpushPayload) {
            if ($args[0] === 'LPUSH') {
                $lpushPayload = json_decode($args[2], true);
            }

            return true;
        }))
        ->twice();

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Starbucks - OK'));

    runWatcher($this);

    expect($lpushPayload['source'])->toBe('cache_miss')
        ->and($lpushPayload['merchant'])->toBe('Starbucks');
    Mockery::close();
});

it('pushes a transaction entry to the redis feed on the fallback path', function () {
    $lpushPayload = null;

    LRedis::shouldReceive('executeRaw')
        ->with(Mockery::on(function ($args) use (&$lpushPayload) {
            if ($args[0] === 'LPUSH') {
                $lpushPayload = json_decode($args[2], true);
            }

            return true;
        }))
        ->twice();

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andThrow(new \RuntimeException('Bad data'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverUnused());

    runWatcher($this);

    expect($lpushPayload['source'])->toBe('fallback');
    Mockery::close();
});

it('trims the feed list to FEED_LENGTH after each push', function () {
    $ltrimArgs = null;

    LRedis::shouldReceive('executeRaw')
        ->with(Mockery::on(function ($args) use (&$ltrimArgs) {
            if ($args[0] === 'LTRIM') {
                $ltrimArgs = $args;
            }

            return true;
        }))
        ->twice();

    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Starbucks - OK'));

    runWatcher($this);

    expect($ltrimArgs[0])->toBe('LTRIM')
        ->and($ltrimArgs[1])->toBe('sentinel:recent_transactions')
        ->and($ltrimArgs[2])->toBe(0)
        ->and($ltrimArgs[3])->toBe(49); // FEED_LENGTH - 1
    Mockery::close();
});

it('increments metrics for each transaction independently', function () {
    $fakeVector = array_fill(0, 1536, 0.1);

    // Stream serves two messages: first a cache hit, then a cache miss
    $calls = 0;
    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('autoClaim')->andReturn([]);
    $stream->shouldReceive('ack')->andReturnNull();
    $stream->shouldReceive('pendingCount')->andReturn(0);
    $stream->shouldReceive('writeLagKey')->andReturnNull();
    $stream->shouldReceive('readGroup')->andReturnUsing(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return ['messages' => [
                fakeStreamMessage(['id' => 'txn-hit-1']),
                fakeStreamMessage(['id' => 'txn-miss-1']),
            ]];
        }
        throw new \RuntimeException('__test_stop__');
    });

    $embedCalls = 0;
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCalls = 0;
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturnUsing(function () use (&$vectorCalls) {
        $vectorCalls++;
        if ($vectorCalls === 1) {
            return [['id' => 'cached', 'score' => 0.99, 'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'OK']]]];
        }

        return [];
    });
    $vectorCache->shouldReceive('upsertNamespace')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, $stream);
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Starbucks - OK'));

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_hit_count'))->toBe(1);
    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
    Mockery::close();
});

// ─── XAUTOCLAIM self-healing pool (ADR-0022) ─────────────────────────────────

it('calls autoClaim before readGroup on every loop iteration', function () {
    $order = [];

    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('ack')->andReturnNull();
    $stream->shouldReceive('pendingCount')->andReturn(0);
    $stream->shouldReceive('writeLagKey')->andReturnNull();
    $stream->shouldReceive('autoClaim')->andReturnUsing(function () use (&$order) {
        $order[] = 'autoClaim';

        return [];
    });
    $stream->shouldReceive('readGroup')->andReturnUsing(function () use (&$order) {
        $order[] = 'readGroup';
        throw new \RuntimeException('__test_stop__');
    });

    $this->app->instance(TransactionStreamService::class, $stream);

    runWatcher($this);

    expect($order)->toBe(['autoClaim', 'readGroup']);
    Mockery::close();
});

it('acks a successfully processed new message', function () {
    $ackedIds = [];

    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('autoClaim')->andReturn([]);
    $stream->shouldReceive('pendingCount')->andReturn(0);
    $stream->shouldReceive('writeLagKey')->andReturnNull();
    $stream->shouldReceive('ack')->andReturnUsing(function ($id) use (&$ackedIds) {
        $ackedIds[] = $id;
    });

    $calls = 0;
    $stream->shouldReceive('readGroup')->andReturnUsing(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return ['messages' => [fakeStreamMessage()]];
        }
        throw new \RuntimeException('__test_stop__');
    });

    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([[
        'id' => 'txn_old',
        'score' => 0.98,
        'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'OK']],
    ]]);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);

    $this->app->instance(TransactionStreamService::class, $stream);
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect($ackedIds)->toBe(['1-0']);
    Mockery::close();
});

it('dead-letters a reclaimed message whose delivery count exceeds the limit', function () {
    $loggedContext = null;
    \Illuminate\Support\Facades\Log::shouldReceive('error')
        ->andReturnUsing(function ($msg, $ctx) use (&$loggedContext) {
            $loggedContext = ['msg' => $msg, 'ctx' => $ctx];
        });

    $orphan = fakeStreamMessage(['id' => 'txn-poison']);

    $ackedIds = [];

    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('pendingCount')->andReturn(0);
    $stream->shouldReceive('writeLagKey')->andReturnNull();
    $stream->shouldReceive('autoClaim')->andReturnUsing(function () use ($orphan) {
        static $first = true;
        if ($first) {
            $first = false;

            return [$orphan];
        }

        return [];
    });
    $stream->shouldReceive('deliveryCount')->with('1-0')->andReturn(5);
    $stream->shouldReceive('ack')->andReturnUsing(function ($id) use (&$ackedIds) {
        $ackedIds[] = $id;
    });

    $stream->shouldReceive('readGroup')->andReturnUsing(function () {
        throw new \RuntimeException('__test_stop__');
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldNotReceive('createTransactionFingerprint');

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, $stream);
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect($ackedIds)->toBe(['1-0'])
        ->and($loggedContext['msg'])->toBe('sentinel:watch dead-letter — delivery count exceeded')
        ->and($loggedContext['ctx']['message_id'])->toBe('1-0')
        ->and($loggedContext['ctx']['stream'])->toBe('transactions');

    Mockery::close();
});

// ─── Consumer lag signal (ADR-0023) ──────────────────────────────────────────

it('writes the pending count to the lag key after every readGroup cycle', function () {
    $writtenLagValues = [];

    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('autoClaim')->andReturn([]);
    $stream->shouldReceive('ack')->andReturnNull();
    $stream->shouldReceive('pendingCount')->andReturn(7);
    $stream->shouldReceive('writeLagKey')->andReturnUsing(function ($v) use (&$writtenLagValues) {
        $writtenLagValues[] = $v;
    });

    $calls = 0;
    $stream->shouldReceive('readGroup')->andReturnUsing(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return ['messages' => [fakeStreamMessage()]];
        }
        throw new \RuntimeException('__test_stop__');
    });

    $fakeVector = array_fill(0, 1536, 0.1);
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fp');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);
    $vectorCache->shouldReceive('upsertNamespace')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, $stream);
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);
    $this->app->instance(ComplianceDriver::class, mockDriverSuccess('low', 'Layer 7 Clear: Costco - OK'));

    runWatcher($this);

    expect($writtenLagValues)->toContain(7);
    Mockery::close();
});
