<?php

use App\Services\EmbeddingService;
use App\Services\ThreatAnalysisService;
use App\Services\ThreatResult;
use App\Services\TransactionStreamService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * A single fake stream message in the format WatchTransactions expects:
 *   $streamMsg[1][1] => JSON-encoded transaction
 */
function fakeStreamMessage(array $overrides = []): array
{
    $data = array_merge([
        'id'            => 'txn-test-1',
        'merchant'      => 'Starbucks',
        'merchant_name' => 'Starbucks',
        'amount'        => 12.50,
        'currency'      => 'USD',
        'type'          => 'purchase',
        'category'      => 'coffee',
        'timestamp'     => '2026-01-01T09:00:00+00:00',
    ], $overrides);

    return ['1-0', ['data', json_encode($data)]];
}

/**
 * Bind a mock stream that serves one message then stops the infinite loop by
 * throwing a sentinel exception on the second read() call.
 *
 * @return \Mockery\MockInterface
 */
function mockStreamWithOneMessage(array $messageOverrides = []): \Mockery\MockInterface
{
    $calls = 0;

    $mock = Mockery::mock(TransactionStreamService::class);
    $mock->shouldReceive('read')
        ->andReturnUsing(function () use (&$calls, $messageOverrides) {
            $calls++;
            if ($calls === 1) {
                return [fakeStreamMessage($messageOverrides)];
            }
            throw new \RuntimeException('__test_stop__');
        });

    return $mock;
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
        'id'       => 'txn_old_abc',
        'score'    => 0.97,
        'metadata' => [
            'analysis' => ['isThreat' => false, 'message' => 'Layer 7 Clear: Starbucks - OK'],
        ],
    ];

    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->once()->andReturn('Amount: 12.50 USD | ...');
    $embedding->shouldReceive('embed')->once()->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->once()->with($fakeVector)->andReturn($cachedResult);
    $vectorCache->shouldNotReceive('upsert');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

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
    $vectorCache->shouldReceive('search')->andReturn([
        'id'       => 'txn_old',
        'score'    => 0.98,
        'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'OK']],
    ]);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldNotReceive('analyze');

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_hit_count'))->toBe(1);
});

// ─── Cache miss path ─────────────────────────────────────────────────────────

it('calls the analyzer and upserts the result on a cache miss', function () {
    $fakeVector = array_fill(0, 1536, 0.3);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->once()->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->once()->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->once()->with($fakeVector)->andReturn(null);
    $vectorCache->shouldReceive('upsert')
        ->once()
        ->with(
            Mockery::on(fn($id) => str_starts_with($id, 'txn_')),
            $fakeVector,
            Mockery::on(fn($meta) => isset($meta['analysis']['isThreat']) && isset($meta['threat_level']))
        );

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->once()
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

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
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')->andReturn(true);

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
});

it('upserts threat_level as "high" when the result is a threat', function () {
    $fakeVector = array_fill(0, 1536, 0.5);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')
        ->once()
        ->with(
            Mockery::any(),
            $fakeVector,
            Mockery::on(fn($meta) => $meta['threat_level'] === 'high' && $meta['analysis']['isThreat'] === true)
        );

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->andReturn(ThreatResult::threat('High value transaction at BigBank ($500.00)', ['merchant' => 'BigBank', 'amount' => 500.00]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage(['amount' => 500.00, 'merchant' => 'BigBank']));
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

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
    $vectorCache->shouldNotReceive('search');
    $vectorCache->shouldNotReceive('upsert');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->once()
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});

it('increments the fallback metric when the vector path throws', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andThrow(new \RuntimeException('network error'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('search');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
});

it('falls back when the vector search itself throws', function () {
    $fakeVector = array_fill(0, 1536, 0.1);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('createTransactionFingerprint')->andReturn('fingerprint');
    $embedding->shouldReceive('embed')->andReturn($fakeVector);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andThrow(new \RuntimeException('Upstash timeout'));
    $vectorCache->shouldNotReceive('upsert');

    $analyzer = Mockery::mock(ThreatAnalysisService::class);
    $analyzer->shouldReceive('analyze')
        ->once()
        ->andReturn(ThreatResult::clear(['merchant' => 'Starbucks', 'amount' => 12.50]));

    $this->app->instance(TransactionStreamService::class, mockStreamWithOneMessage());
    $this->app->instance(EmbeddingService::class, $embedding);
    $this->app->instance(VectorCacheService::class, $vectorCache);
    $this->app->instance(ThreatAnalysisService::class, $analyzer);

    runWatcher($this);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});
