<?php

use App\Models\Transaction;
use App\Services\EmbeddingService;
use App\Services\ThreatAnalysisService;
use App\Services\ThreatResult;
use App\Services\TransactionProcessorService;
use App\Services\VectorCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class, RefreshDatabase::class);

// ─── Fixtures ─────────────────────────────────────────────────────────────────

$baseTxn = [
    'id'            => 'txn-unit-001',
    'merchant'      => 'ACME Corp',
    'merchant_name' => 'ACME Corp',
    'amount'        => 150.00,
    'currency'      => 'AUD',
];

$vector = array_fill(0, 1536, 0.1);

// ─── Mock builders ────────────────────────────────────────────────────────────

function tps_processor(
    EmbeddingService      $e,
    ThreatAnalysisService $a,
    VectorCacheService    $vc,
): TransactionProcessorService {
    return new TransactionProcessorService($e, $a, $vc);
}

function tps_embeds(array $vector): EmbeddingService
{
    $m = Mockery::mock(EmbeddingService::class);
    $m->shouldReceive('createTransactionFingerprint')->andReturn('fp');
    $m->shouldReceive('embed')->andReturn($vector);
    return $m;
}

function tps_embeddingFails(): EmbeddingService
{
    $m = Mockery::mock(EmbeddingService::class);
    $m->shouldReceive('createTransactionFingerprint')
      ->andThrow(new RuntimeException('Gemini quota exceeded'));
    return $m;
}

function tps_cacheHit(bool $isThreat): VectorCacheService
{
    $m = Mockery::mock(VectorCacheService::class);
    $m->shouldReceive('search')->andReturn([
        'id'       => 'txn_cached_abc',
        'score'    => 0.97,
        'metadata' => [
            'analysis' => [
                'isThreat' => $isThreat,
                'message'  => $isThreat ? 'Cached: threat detected' : 'Layer 7 Clear: ACME Corp - OK',
            ],
        ],
    ]);
    $m->shouldNotReceive('upsert');
    return $m;
}

function tps_cacheMiss(): VectorCacheService
{
    $m = Mockery::mock(VectorCacheService::class);
    $m->shouldReceive('search')->andReturn(null);
    $m->shouldReceive('upsert')->andReturn(true);
    return $m;
}

function tps_cacheUnused(): VectorCacheService
{
    $m = Mockery::mock(VectorCacheService::class);
    $m->shouldNotReceive('search');
    $m->shouldNotReceive('upsert');
    return $m;
}

function tps_analyzes(ThreatResult $result): ThreatAnalysisService
{
    $m = Mockery::mock(ThreatAnalysisService::class);
    $m->shouldReceive('analyze')->andReturn($result);
    return $m;
}

function tps_analyzerUnused(): ThreatAnalysisService
{
    $m = Mockery::mock(ThreatAnalysisService::class);
    $m->shouldNotReceive('analyze');
    return $m;
}

function tps_allowRedis(): void
{
    Redis::shouldReceive('executeRaw')->andReturn(1);
}

// ─── Dataset ──────────────────────────────────────────────────────────────────

dataset('source × outcome', function () {
    $v = array_fill(0, 1536, 0.1);

    // ThreatResult constructed inside factory closures so they resolve after autoload is ready.
    yield 'cache_hit / clear' => [
        fn () => tps_processor(tps_embeds($v), tps_analyzerUnused(), tps_cacheHit(false)),
        'cache_hit', false, 'Layer 7 Clear',
    ];
    yield 'cache_hit / threat' => [
        fn () => tps_processor(tps_embeds($v), tps_analyzerUnused(), tps_cacheHit(true)),
        'cache_hit', true, 'Cached: threat',
    ];
    yield 'cache_miss / clear' => [
        fn () => tps_processor(
            tps_embeds($v),
            tps_analyzes(ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00])),
            tps_cacheMiss()
        ),
        'cache_miss', false, 'Layer 7 Clear',
    ];
    yield 'cache_miss / threat' => [
        fn () => tps_processor(
            tps_embeds($v),
            tps_analyzes(ThreatResult::threat('High value at ACME Corp ($500.00)', ['merchant' => 'ACME Corp', 'amount' => 500.00])),
            tps_cacheMiss()
        ),
        'cache_miss', true, 'High value',
    ];
    yield 'fallback / clear' => [
        fn () => tps_processor(
            tps_embeddingFails(),
            tps_analyzes(ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00])),
            tps_cacheUnused()
        ),
        'fallback', false, 'Layer 7 Clear',
    ];
    yield 'fallback / threat' => [
        fn () => tps_processor(
            tps_embeddingFails(),
            tps_analyzes(ThreatResult::threat('High value at ACME Corp ($500.00)', ['merchant' => 'ACME Corp', 'amount' => 500.00])),
            tps_cacheUnused()
        ),
        'fallback', true, 'High value',
    ];
});

// ─── Setup ────────────────────────────────────────────────────────────────────

beforeEach(fn () => Cache::flush());

// ─── Return shape ─────────────────────────────────────────────────────────────

it('returns the correct shape, source, and outcome for every pipeline path', function (
    Closure $factory,
    string  $expectedSource,
    bool    $expectedThreat,
    string  $expectedMessage,
) use ($baseTxn) {
    tps_allowRedis();

    $result = $factory()->process($baseTxn);

    expect($result)
        ->toHaveKeys(['source', 'is_threat', 'message', 'elapsed_ms'])
        ->and($result['source'])->toBe($expectedSource)
        ->and($result['is_threat'])->toBe($expectedThreat)
        ->and($result['message'])->toContain($expectedMessage)
        ->and($result['elapsed_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    Mockery::close();
})->with('source × outcome');

// ─── Metric counters ──────────────────────────────────────────────────────────

it('increments sentinel_metrics_cache_hit_count on a cache hit', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    tps_processor(tps_embeds($vector), tps_analyzerUnused(), tps_cacheHit(false))->process($baseTxn);

    expect(Cache::get('sentinel_metrics_cache_hit_count'))->toBe(1);
    Mockery::close();
});

it('increments sentinel_metrics_cache_miss_count on a cache miss', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    tps_processor(tps_embeds($vector), tps_analyzes($clear), tps_cacheMiss())->process($baseTxn);

    expect(Cache::get('sentinel_metrics_cache_miss_count'))->toBe(1);
    Mockery::close();
});

it('increments sentinel_metrics_fallback_count when the embedding call fails', function () use ($baseTxn) {
    tps_allowRedis();
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    tps_processor(tps_embeddingFails(), tps_analyzes($clear), tps_cacheUnused())->process($baseTxn);

    expect(Cache::get('sentinel_metrics_fallback_count'))->toBe(1);
    Mockery::close();
});

it('increments sentinel_metrics_threat_count only when the result is a threat', function (bool $isThreat) use ($baseTxn, $vector) {
    tps_allowRedis();
    $result = $isThreat
        ? ThreatResult::threat('High value at ACME Corp ($500.00)', ['merchant' => 'ACME Corp', 'amount' => 500.00])
        : ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);

    tps_processor(tps_embeds($vector), tps_analyzes($result), tps_cacheMiss())->process($baseTxn);

    $isThreat
        ? expect(Cache::get('sentinel_metrics_threat_count'))->toBe(1)
        : expect(Cache::get('sentinel_metrics_threat_count'))->toBeNull();

    Mockery::close();
})->with([
    'threat'     => [true],
    'not threat' => [false],
]);

// ─── Upsert metadata ──────────────────────────────────────────────────────────

it('upserts threat_level "high" and isThreat true on a threatening cache miss', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    $threat    = ThreatResult::threat('High value', ['merchant' => 'ACME Corp', 'amount' => 500.00]);
    $captured  = null;

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')
        ->once()
        ->andReturnUsing(function ($id, $vec, $meta) use (&$captured) {
            $captured = $meta;
            return true;
        });

    tps_processor(tps_embeds($vector), tps_analyzes($threat), $vectorCache)->process($baseTxn);

    expect($captured['threat_level'])->toBe('high')
        ->and($captured['analysis']['isThreat'])->toBeTrue();
    Mockery::close();
});

it('upserts threat_level "low" and isThreat false on a clear cache miss', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    $clear    = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    $captured = null;

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')
        ->once()
        ->andReturnUsing(function ($id, $vec, $meta) use (&$captured) {
            $captured = $meta;
            return true;
        });

    tps_processor(tps_embeds($vector), tps_analyzes($clear), $vectorCache)->process($baseTxn);

    expect($captured['threat_level'])->toBe('low')
        ->and($captured['analysis']['isThreat'])->toBeFalse();
    Mockery::close();
});

it('upserts the current policy_epoch with the analysis result', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    Cache::put('sentinel_policy_epoch', 'epoch-abc123');
    $clear    = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    $captured = null;

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')
        ->once()
        ->andReturnUsing(function ($id, $vec, $meta) use (&$captured) {
            $captured = $meta;
            return true;
        });

    tps_processor(tps_embeds($vector), tps_analyzes($clear), $vectorCache)->process($baseTxn);

    expect($captured['policy_epoch'])->toBe('epoch-abc123');
    Mockery::close();
});

it('upserts null policy_epoch when no ingest has run', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    // Cache is flushed in beforeEach — no epoch set
    $clear    = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    $captured = null;

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')
        ->once()
        ->andReturnUsing(function ($id, $vec, $meta) use (&$captured) {
            $captured = $meta;
            return true;
        });

    tps_processor(tps_embeds($vector), tps_analyzes($clear), $vectorCache)->process($baseTxn);

    expect($captured['policy_epoch'])->toBeNull();
    Mockery::close();
});

// ─── Policy epoch validation ──────────────────────────────────────────────────

it('serves a cache hit when the stored epoch matches the current epoch', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    Cache::put('sentinel_policy_epoch', 'epoch-v1');

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn([
        'id'    => 'txn_cached',
        'score' => 0.97,
        'metadata' => [
            'policy_epoch' => 'epoch-v1',
            'analysis'     => ['isThreat' => false, 'message' => 'Layer 7 Clear'],
        ],
    ]);
    $vectorCache->shouldNotReceive('upsert');

    $result = tps_processor(tps_embeds($vector), tps_analyzerUnused(), $vectorCache)->process($baseTxn);

    expect($result['source'])->toBe('cache_hit');
    Mockery::close();
});

it('re-analyzes and re-upserts when the cached epoch is stale', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    Cache::put('sentinel_policy_epoch', 'epoch-v2');
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn([
        'id'    => 'txn_cached',
        'score' => 0.97,
        'metadata' => [
            'policy_epoch' => 'epoch-v1', // old epoch
            'analysis'     => ['isThreat' => false, 'message' => 'Stale verdict'],
        ],
    ]);
    $vectorCache->shouldReceive('upsert')->once()->andReturn(true);

    $analyzer = tps_analyzes($clear);
    $result   = tps_processor(tps_embeds($vector), $analyzer, $vectorCache)->process($baseTxn);

    expect($result['source'])->toBe('cache_miss');
    Mockery::close();
});

it('serves a cache hit when no ingest has ever run (both epochs null)', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    // No epoch in Cache (beforeEach flushes), no epoch in cached metadata

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn([
        'id'    => 'txn_cached',
        'score' => 0.97,
        'metadata' => [
            // no policy_epoch key — pre-epoch entry
            'analysis' => ['isThreat' => false, 'message' => 'Layer 7 Clear'],
        ],
    ]);
    $vectorCache->shouldNotReceive('upsert');

    $result = tps_processor(tps_embeds($vector), tps_analyzerUnused(), $vectorCache)->process($baseTxn);

    expect($result['source'])->toBe('cache_hit');
    Mockery::close();
});

it('re-analyzes a pre-epoch entry once ingest has run', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    Cache::put('sentinel_policy_epoch', 'epoch-v1'); // ingest has run
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn([
        'id'    => 'txn_cached',
        'score' => 0.97,
        'metadata' => [
            // no policy_epoch — entry predates the epoch feature
            'analysis' => ['isThreat' => false, 'message' => 'Old verdict without policy grounding'],
        ],
    ]);
    $vectorCache->shouldReceive('upsert')->once()->andReturn(true);

    $result = tps_processor(tps_embeds($vector), tps_analyzes($clear), $vectorCache)->process($baseTxn);

    expect($result['source'])->toBe('cache_miss');
    Mockery::close();
});

// ─── Transaction persistence ──────────────────────────────────────────────────

it('persists a Transaction with correct fields on a cache hit', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    tps_processor(tps_embeds($vector), tps_analyzerUnused(), tps_cacheHit(false))->process($baseTxn);

    $txn = Transaction::first();
    expect($txn)->not->toBeNull()
        ->and($txn->txn_id)->toBe('txn-unit-001')
        ->and($txn->merchant)->toBe('ACME Corp')
        ->and($txn->currency)->toBe('AUD')
        ->and($txn->is_threat)->toBeFalse()
        ->and($txn->source)->toBe('cache_hit')
        ->and((float) $txn->amount)->toBe(150.0);

    Mockery::close();
});

it('persists a Transaction with correct source on a cache miss', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    tps_processor(tps_embeds($vector), tps_analyzes($clear), tps_cacheMiss())->process($baseTxn);

    $txn = Transaction::first();
    expect($txn)->not->toBeNull()
        ->and($txn->source)->toBe('cache_miss')
        ->and($txn->is_threat)->toBeFalse();

    Mockery::close();
});

it('marks the Transaction as a threat when the result is a threat', function () use ($baseTxn, $vector) {
    tps_allowRedis();
    $threat = ThreatResult::threat('High value', ['merchant' => 'ACME Corp', 'amount' => 500.00]);
    tps_processor(tps_embeds($vector), tps_analyzes($threat), tps_cacheMiss())
        ->process([...$baseTxn, 'amount' => 500.00]);

    expect(Transaction::first()->is_threat)->toBeTrue();
    Mockery::close();
});

// ─── observe=false ────────────────────────────────────────────────────────────

it('records no metrics and no Transaction when observe is false (cache hit)', function () use ($baseTxn, $vector) {
    Redis::shouldReceive('executeRaw')->never();

    $result = tps_processor(tps_embeds($vector), tps_analyzerUnused(), tps_cacheHit(false))
        ->process($baseTxn, false);

    expect($result['source'])->toBe('cache_hit')
        ->and(Cache::get('sentinel_metrics_cache_hit_count'))->toBeNull()
        ->and(Transaction::count())->toBe(0);

    Mockery::close();
});

it('records no metrics and no Transaction when observe is false (cache miss)', function () use ($baseTxn, $vector) {
    Redis::shouldReceive('executeRaw')->never();
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);

    $result = tps_processor(tps_embeds($vector), tps_analyzes($clear), tps_cacheMiss())
        ->process($baseTxn, false);

    expect($result['source'])->toBe('cache_miss')
        ->and(Cache::get('sentinel_metrics_cache_miss_count'))->toBeNull()
        ->and(Transaction::count())->toBe(0);

    Mockery::close();
});

it('still upserts into the vector cache when observe is false', function () use ($baseTxn, $vector) {
    Redis::shouldReceive('executeRaw')->never();
    $clear    = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 150.00]);
    $upserted = false;

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('search')->andReturn(null);
    $vectorCache->shouldReceive('upsert')->once()->andReturnUsing(function () use (&$upserted) {
        $upserted = true;
        return true;
    });

    tps_processor(tps_embeds($vector), tps_analyzes($clear), $vectorCache)->process($baseTxn, false);

    expect($upserted)->toBeTrue();
    Mockery::close();
});

// ─── Field extraction ─────────────────────────────────────────────────────────

it('falls back to merchant_name when the merchant field is absent', function () use ($vector) {
    tps_allowRedis();
    $txn   = ['id' => 'txn-name-fb', 'merchant_name' => 'Fallback Corp', 'amount' => 25.00, 'currency' => 'USD'];
    $clear = ThreatResult::clear(['merchant' => 'Fallback Corp', 'amount' => 25.00]);

    tps_processor(tps_embeds($vector), tps_analyzes($clear), tps_cacheMiss())->process($txn);

    expect(Transaction::first()->merchant)->toBe('Fallback Corp');
    Mockery::close();
});

it('stores null amount in the DB when the transaction has no amount field', function () use ($vector) {
    tps_allowRedis();
    $txn   = ['id' => 'txn-no-amt', 'merchant' => 'ACME Corp', 'currency' => 'EUR'];
    $clear = ThreatResult::clear(['merchant' => 'ACME Corp', 'amount' => 0]);

    tps_processor(tps_embeds($vector), tps_analyzes($clear), tps_cacheMiss())->process($txn);

    expect(Transaction::first()->amount)->toBeNull();
    Mockery::close();
});

it('auto-generates a txn_id when none is provided', function () use ($vector) {
    tps_allowRedis();
    $txn   = ['merchant' => 'No ID Corp', 'amount' => 10.00, 'currency' => 'AUD'];
    $clear = ThreatResult::clear(['merchant' => 'No ID Corp', 'amount' => 10.00]);

    tps_processor(tps_embeds($vector), tps_analyzes($clear), tps_cacheMiss())->process($txn);

    expect(Transaction::first()->txn_id)->toStartWith('txn_');
    Mockery::close();
});
