<?php

use App\Services\Compliance\GeminiDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function geminiAxiom(array $overrides = []): array
{
    return array_merge([
        'status'        => 'critical',
        'metric_value'  => 94.0,
        'anomaly_score' => 0.91,
        'source_id'     => 'sensor-42',
        'emitted_at'    => '2026-04-01T10:00:00Z',
    ], $overrides);
}

function geminiResponse(array $body): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => [['text' => json_encode($body)]],
            ],
        ]],
    ];
}

function mockGeminiDriver(array $responseBody): GeminiDriver
{
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response($responseBody, 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    return new GeminiDriver($embedding, $vectorCache);
}

// ─── analyze() ───────────────────────────────────────────────────────────────

it('returns a parsed narrative from a well-formed Gemini response', function () {
    $result = mockGeminiDriver(geminiResponse([
        'narrative'   => 'AML violation detected.',
        'risk_level'  => 'critical',
        'policy_refs' => ['AML-12'],
        'confidence'  => 0.97,
    ]))->analyze(geminiAxiom());

    expect($result['narrative'])->toBe('AML violation detected.')
        ->and($result['risk_level'])->toBe('critical')
        ->and($result['confidence'])->toBe(0.97);
});

it('strips markdown code fences before parsing JSON', function () {
    $inner = json_encode(['narrative' => 'Fenced.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.8]);

    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['parts' => [['text' => "```json\n{$inner}\n```"]]],
        ]],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $result = (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom());

    expect($result['narrative'])->toBe('Fenced.');
});

it('returns unknown fallback when response shape is unexpected', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'unexpected response shape'));
    Log::shouldReceive('info')->once();

    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'not json']]]]],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $result = (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom());

    expect($result['narrative'])->toBeNull()
        ->and($result['risk_level'])->toBe('unknown');
});

it('throws when the Gemini Flash API returns a non-2xx status', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Unauthorized'], 401)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    expect(fn () => (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom()))
        ->toThrow(\RuntimeException::class, 'GeminiDriver: Flash API call failed');
});

it('proceeds without policy context when RAG embedding throws', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(
        geminiResponse(['narrative' => 'No context.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.7]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->once()->andThrow(new \RuntimeException('embedding failed'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');

    $result = (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom());

    expect($result)->toBeArray();
});

// ─── Domain filtering ─────────────────────────────────────────────────────────

it('passes domain filter to searchNamespace when domain key is present in data', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(
        geminiResponse(['narrative' => 'AML audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) =>
            $ns === 'policies' && $filter === "domain = 'aml'"
        )
        ->andReturn([]);

    (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom(['domain' => 'aml']));
});

it('passes null filter to searchNamespace when domain key is absent', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(
        geminiResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) =>
            $ns === 'policies' && $filter === null
        )
        ->andReturn([]);

    (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom());
});

// ─── Retrieval logging ────────────────────────────────────────────────────────

it('logs retrieval info with domain and filter_used true when domain is present', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(
        geminiResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([
        ['id' => 'aml_0', 'score' => 0.88, 'metadata' => []],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('GeminiDriver: policy RAG retrieval', Mockery::on(fn ($ctx) =>
            $ctx['domain'] === 'aml' &&
            $ctx['filter_used'] === true &&
            $ctx['chunk_count'] === 1 &&
            $ctx['scores'] === [0.88]
        ));

    (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom(['domain' => 'aml']));
});

it('logs retrieval info with null domain and filter_used false when domain is absent', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(
        geminiResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')
        ->once()
        ->with('GeminiDriver: policy RAG retrieval', Mockery::on(fn ($ctx) =>
            $ctx['domain'] === null &&
            $ctx['filter_used'] === false &&
            $ctx['chunk_count'] === 0
        ));

    (new GeminiDriver($embedding, $vectorCache))->analyze(geminiAxiom());
});
