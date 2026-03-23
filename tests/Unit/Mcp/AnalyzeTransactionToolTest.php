<?php

use App\Mcp\Servers\SentinelServer;
use App\Mcp\Tools\AnalyzeTransaction;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

$fakeVector = array_fill(0, 1536, 0.1);

beforeEach(function () {
    config([
        'services.gemini.api_key'              => 'test-key',
        'services.upstash_vector.url'          => 'https://fake-vector.upstash.io',
        'services.upstash_vector.token'        => 'fake-token',
        'services.upstash_vector.similarity_threshold' => 0.95,
        'sentinel.thresholds.high_risk'        => 400.00,
    ]);
    // observe: false is passed by AnalyzeTransaction — metrics and feed are NOT written,
    // so no Redis/Cache mocking is needed here.
});

// ─── cache hit path ───────────────────────────────────────────────────────────

it('returns a cache_hit result when the vector cache has a matching entry', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/query'        => Http::response([
            'result' => [[
                'id'    => 'txn_abc',
                'score' => 0.97,
                'metadata' => [
                    'analysis' => ['isThreat' => false, 'message' => 'Layer 7 Clear: Shell Gas - OK'],
                ],
            ]],
        ], 200),
    ]);

    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 50.00,
        'currency' => 'USD',
        'merchant' => 'Shell Gas',
    ]);

    $response->assertOk()->assertSee('cache_hit');
});

// ─── cache miss / threat path ─────────────────────────────────────────────────

it('returns is_threat true for a high-value transaction on cache miss', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/query'        => Http::response(['result' => []], 200),
        '*/upsert'       => Http::response(['result' => 'Success'], 200),
    ]);

    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 9000.00,
        'currency' => 'USD',
        'merchant' => 'Casino Royale',
    ]);

    $response->assertOk()->assertSee('"is_threat": true');
});

it('returns is_threat false for a low-value transaction on cache miss', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/query'        => Http::response(['result' => []], 200),
        '*/upsert'       => Http::response(['result' => 'Success'], 200),
    ]);

    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 12.50,
        'currency' => 'CAD',
        'merchant' => 'Tim Hortons',
    ]);

    $response->assertOk()->assertSee('"is_threat": false');
});

// ─── fallback path ────────────────────────────────────────────────────────────

it('still returns a result when embedding fails (fallback path)', function () {
    Http::fake([
        '*embedContent*' => Http::response(['error' => 'service unavailable'], 503),
    ]);

    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 150.00,
        'currency' => 'USD',
        'merchant' => 'Walmart',
    ]);

    $response->assertOk()->assertSee('fallback');
});

// ─── validation ───────────────────────────────────────────────────────────────

it('returns a validation error when amount is missing', function () {
    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'currency' => 'USD',
        'merchant' => 'Shell Gas',
    ]);

    $response->assertHasErrors();
});

it('returns a validation error when currency is missing', function () {
    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 50.00,
        'merchant' => 'Shell Gas',
    ]);

    $response->assertHasErrors();
});

it('returns a validation error when merchant is missing', function () {
    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 50.00,
        'currency' => 'USD',
    ]);

    $response->assertHasErrors();
});

it('returns a validation error when amount is negative', function () {
    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => -5.00,
        'currency' => 'USD',
        'merchant' => 'Shell Gas',
    ]);

    $response->assertHasErrors();
});

// ─── response shape ───────────────────────────────────────────────────────────

it('response contains source, is_threat, message and elapsed_ms keys', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/query'        => Http::response(['result' => []], 200),
        '*/upsert'       => Http::response(['result' => 'Success'], 200),
    ]);

    $response = SentinelServer::tool(AnalyzeTransaction::class, [
        'amount'   => 25.00,
        'currency' => 'USD',
        'merchant' => 'Starbucks',
    ]);

    $response->assertOk()
        ->assertSee('source')
        ->assertSee('is_threat')
        ->assertSee('message')
        ->assertSee('elapsed_ms');
});
