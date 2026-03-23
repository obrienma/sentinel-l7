<?php

use App\Mcp\Servers\SentinelServer;
use App\Mcp\Tools\GetRecentTransactions;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class);

// ─── happy path ───────────────────────────────────────────────────────────────

it('returns transactions from the Redis feed', function () {
    $entry = json_encode([
        'id'        => 'txn_001',
        'merchant'  => 'Shell Gas',
        'amount'    => '50.00',
        'currency'  => 'USD',
        'is_threat' => false,
        'source'    => 'cache_hit',
    ]);

    Redis::shouldReceive('lrange')
        ->once()
        ->with('sentinel:recent_transactions', 0, 19)
        ->andReturn([$entry]);

    $response = SentinelServer::tool(GetRecentTransactions::class);

    $response->assertOk()
        ->assertSee('Shell Gas')
        ->assertSee('"count": 1');
});

it('returns an empty list when the feed is empty', function () {
    Redis::shouldReceive('lrange')
        ->once()
        ->andReturn([]);

    $response = SentinelServer::tool(GetRecentTransactions::class);

    $response->assertOk()
        ->assertSee('"count": 0');
});

it('silently skips malformed JSON entries in the feed', function () {
    Redis::shouldReceive('lrange')
        ->once()
        ->andReturn([
            json_encode(['id' => 'txn_good', 'merchant' => 'Walmart']),
            'not-valid-json',
        ]);

    $response = SentinelServer::tool(GetRecentTransactions::class);

    $response->assertOk()
        ->assertSee('Walmart')
        ->assertSee('"count": 1')
        ->assertDontSee('not-valid-json');
});

// ─── limit parameter ─────────────────────────────────────────────────────────

it('requests the correct number of entries based on limit', function () {
    Redis::shouldReceive('lrange')
        ->once()
        ->with('sentinel:recent_transactions', 0, 4)
        ->andReturn([]);

    $response = SentinelServer::tool(GetRecentTransactions::class, ['limit' => 5]);

    $response->assertOk();
});

it('defaults to 20 entries when no limit is specified', function () {
    Redis::shouldReceive('lrange')
        ->once()
        ->with('sentinel:recent_transactions', 0, 19)
        ->andReturn([]);

    SentinelServer::tool(GetRecentTransactions::class)->assertOk();
});

// ─── validation ───────────────────────────────────────────────────────────────

it('returns a validation error when limit is zero', function () {
    $response = SentinelServer::tool(GetRecentTransactions::class, ['limit' => 0]);

    $response->assertHasErrors();
});

it('returns a validation error when limit exceeds 50', function () {
    $response = SentinelServer::tool(GetRecentTransactions::class, ['limit' => 51]);

    $response->assertHasErrors();
});

// ─── response shape ───────────────────────────────────────────────────────────

it('response contains transactions and count keys', function () {
    Redis::shouldReceive('lrange')->once()->andReturn([]);

    $response = SentinelServer::tool(GetRecentTransactions::class);

    $response->assertOk()
        ->assertSee('transactions')
        ->assertSee('count');
});
