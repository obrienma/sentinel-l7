<?php

use App\Mcp\Servers\SentinelServer;
use App\Mcp\Tools\SearchPolicies;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

$fakeVector = array_fill(0, 1536, 0.1);

beforeEach(function () {
    config([
        'services.gemini.api_key'     => 'test-key',
        'services.upstash_vector.url'   => 'https://fake-vector.upstash.io',
        'services.upstash_vector.token' => 'fake-token',
    ]);
});

// ─── happy path ───────────────────────────────────────────────────────────────

it('returns matching policy chunks above the 0.70 threshold', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*'     => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/namespaces/policies/query' => Http::response([
            'result' => [
                ['id' => 'aml-001-threshold', 'score' => 0.88, 'metadata' => ['title' => 'AML Threshold Rule']],
                ['id' => 'bsa-structuring',   'score' => 0.72, 'metadata' => ['title' => 'BSA Structuring']],
            ],
        ], 200),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, [
        'query' => 'cash transaction above $10,000',
    ]);

    $response->assertOk()
        ->assertSee('aml-001-threshold')
        ->assertSee('BSA Structuring')
        ->assertSee('"count": 2');
});

it('filters out results below the 0.70 threshold', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*'     => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/namespaces/policies/query' => Http::response([
            'result' => [
                ['id' => 'aml-001',    'score' => 0.85, 'metadata' => []],
                ['id' => 'irrelevant', 'score' => 0.55, 'metadata' => []],
            ],
        ], 200),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, [
        'query' => 'wire transfer',
    ]);

    $response->assertOk()
        ->assertSee('aml-001')
        ->assertDontSee('irrelevant')
        ->assertSee('"count": 1');
});

it('returns empty policies array when no results exceed the threshold', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*'     => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/namespaces/policies/query' => Http::response([
            'result' => [
                ['id' => 'low-match', 'score' => 0.40, 'metadata' => []],
            ],
        ], 200),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, [
        'query' => 'completely unrelated query',
    ]);

    $response->assertOk()
        ->assertSee('"count": 0')
        ->assertDontSee('low-match');
});

it('returns empty policies when Upstash is unavailable', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*'     => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/namespaces/policies/query' => Http::response(null, 503),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, [
        'query' => 'HIPAA protected health information',
    ]);

    // VectorCacheService logs the failure and returns [] — tool returns empty result gracefully
    $response->assertOk()
        ->assertSee('"count": 0')
        ->assertDontSee('error');
});

// ─── validation ───────────────────────────────────────────────────────────────

it('returns a validation error when query is missing', function () {
    $response = SentinelServer::tool(SearchPolicies::class, []);

    $response->assertHasErrors();
});

it('returns a validation error when query is too short', function () {
    $response = SentinelServer::tool(SearchPolicies::class, ['query' => 'ab']);

    $response->assertHasErrors();
});

it('returns a validation error when limit exceeds 10', function () {
    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 1536, 0.1)]], 200),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, [
        'query' => 'AML structuring rules',
        'limit' => 99,
    ]);

    $response->assertHasErrors();
});

// ─── response shape ───────────────────────────────────────────────────────────

it('response contains policies and count keys', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*'     => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/namespaces/policies/query' => Http::response(['result' => []], 200),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, ['query' => 'GDPR data retention']);

    $response->assertOk()
        ->assertSee('policies')
        ->assertSee('count');
});

it('scores in the response are rounded to 4 decimal places', function () use ($fakeVector) {
    Http::fake([
        '*embedContent*'     => Http::response(['embedding' => ['values' => $fakeVector]], 200),
        '*/namespaces/policies/query' => Http::response([
            'result' => [
                ['id' => 'gdpr-001', 'score' => 0.812345678, 'metadata' => []],
            ],
        ], 200),
    ]);

    $response = SentinelServer::tool(SearchPolicies::class, ['query' => 'GDPR data deletion']);

    $response->assertOk()->assertSee('0.8123');
});
