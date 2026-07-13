<?php

use App\Services\Compliance\VertexAIDriver;
use App\Services\Compliance\VertexAiTokenService;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function vertexAxiom(array $overrides = []): array
{
    return array_merge([
        'status' => 'critical',
        'metric_value' => 94.0,
        'anomaly_score' => 0.91,
        'source_id' => 'sensor-42',
        'emitted_at' => '2026-04-01T10:00:00Z',
    ], $overrides);
}

function vertexResponse(array $body): array
{
    return [
        'content' => [
            ['type' => 'text', 'text' => json_encode($body)],
        ],
    ];
}

function mockVertexTokenService(): VertexAiTokenService
{
    $tokenService = Mockery::mock(VertexAiTokenService::class);
    $tokenService->shouldReceive('fetchAccessToken')->andReturn('fake-access-token');

    return $tokenService;
}

function mockVertexDriver(array $responseBody): VertexAIDriver
{
    Http::fake(['https://*-aiplatform.googleapis.com/*' => Http::response($responseBody, 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    return new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService());
}

// ─── analyze() ───────────────────────────────────────────────────────────────

it('returns a parsed narrative from a well-formed Vertex AI response', function () {
    $result = mockVertexDriver(vertexResponse([
        'narrative' => 'AML violation detected.',
        'risk_level' => 'critical',
        'policy_refs' => ['AML-12'],
        'confidence' => 0.97,
    ]))->analyze(vertexAxiom());

    expect($result['narrative'])->toBe('AML violation detected.')
        ->and($result['risk_level'])->toBe('critical')
        ->and($result['confidence'])->toBe(0.97);
});

it('sends the minted access token as a bearer token', function () {
    $captured = null;

    Http::fake(function ($request) use (&$captured) {
        $captured = $request->header('Authorization')[0] ?? null;

        return Http::response(vertexResponse([
            'narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ]), 200);
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyze(vertexAxiom());

    expect($captured)->toBe('Bearer fake-access-token');
});

it('sends thinking disabled and low effort to avoid Sonnet 4.6\'s expensive default', function () {
    $captured = null;

    Http::fake(function ($request) use (&$captured) {
        $captured = $request->data();

        return Http::response(vertexResponse([
            'narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ]), 200);
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyze(vertexAxiom());

    expect($captured['anthropic_version'])->toBe('vertex-2023-10-16')
        ->and($captured['thinking'])->toBe(['type' => 'disabled'])
        ->and($captured['output_config'])->toBe(['effort' => 'low'])
        ->and($captured['messages'][0]['role'])->toBe('user');
});

it('throws when the Vertex AI rawPredict call returns a non-2xx status', function () {
    Http::fake(['https://*-aiplatform.googleapis.com/*' => Http::response(['error' => 'Unauthorized'], 401)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    expect(fn () => (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyze(vertexAxiom()))
        ->toThrow(\RuntimeException::class, 'VertexAIDriver: rawPredict call failed');
});

it('throws when the token service fails to mint an access token', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $tokenService = Mockery::mock(VertexAiTokenService::class);
    $tokenService->shouldReceive('fetchAccessToken')
        ->andThrow(new \RuntimeException('VertexAiTokenService: failed to mint OAuth2 access token'));

    expect(fn () => (new VertexAIDriver($embedding, $vectorCache, $tokenService))->analyze(vertexAxiom()))
        ->toThrow(\RuntimeException::class, 'failed to mint OAuth2 access token');
});

it('strips markdown code fences before parsing JSON', function () {
    $inner = json_encode(['narrative' => 'Fenced.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.8]);

    Http::fake(['https://*-aiplatform.googleapis.com/*' => Http::response([
        'content' => [
            ['type' => 'text', 'text' => "```json\n{$inner}\n```"],
        ],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $result = (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyze(vertexAxiom());

    expect($result['narrative'])->toBe('Fenced.');
});

it('returns unknown fallback when response shape is unexpected', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'unexpected response shape'));
    Log::shouldReceive('warning')
        ->once()
        ->with('VertexAIDriver: low quality score', Mockery::type('array'));
    Log::shouldReceive('info')->twice();

    Http::fake(['https://*-aiplatform.googleapis.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'not json']],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $result = (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyze(vertexAxiom());

    expect($result['narrative'])->toBeNull()
        ->and($result['risk_level'])->toBe('unknown');
});

// ─── Domain filtering ─────────────────────────────────────────────────────────

it('passes domain filter to searchNamespace when domain key is present in data', function () {
    Http::fake(['https://*-aiplatform.googleapis.com/*' => Http::response(
        vertexResponse(['narrative' => 'AML audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) => $ns === 'policies' && $filter === "domain = 'aml'"
        )
        ->andReturn([]);

    (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyze(vertexAxiom(['domain' => 'aml']));
});

// ─── analyzeTransaction() ──────────────────────────────────────────────────────

function vertexTransaction(array $overrides = []): array
{
    return array_merge([
        'id' => 'txn-42',
        'merchant' => 'ACME Corp',
        'amount' => 500.00,
        'currency' => 'AUD',
    ], $overrides);
}

it('returns a parsed narrative from a well-formed transaction analysis response', function () {
    $result = mockVertexDriver(vertexResponse([
        'narrative' => 'High value transaction requires review.',
        'risk_level' => 'high',
        'policy_refs' => ['AML-3'],
        'confidence' => 0.88,
    ]))->analyzeTransaction(vertexTransaction());

    expect($result['narrative'])->toBe('High value transaction requires review.')
        ->and($result['risk_level'])->toBe('high')
        ->and($result['confidence'])->toBe(0.88);
});

it('builds the transaction prompt from the transaction-compliance-analysis template', function () {
    $captured = null;

    Http::fake(function ($request) use (&$captured) {
        $captured = $request->data()['messages'][0]['content'];

        return Http::response(vertexResponse([
            'narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ]), 200);
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    (new VertexAIDriver($embedding, $vectorCache, mockVertexTokenService()))->analyzeTransaction(vertexTransaction());

    expect($captured)
        ->toContain('Merchant: ACME Corp')
        ->toContain('Amount: 500')
        ->toContain('Currency: AUD')
        ->not->toContain('Anomaly score');
});
