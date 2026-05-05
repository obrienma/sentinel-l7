<?php

use App\Services\Compliance\OpenRouterDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function openRouterAxiom(array $overrides = []): array
{
    return array_merge([
        'status' => 'critical',
        'metric_value' => 94.0,
        'anomaly_score' => 0.91,
        'source_id' => 'sensor-42',
        'emitted_at' => '2026-04-01T10:00:00Z',
    ], $overrides);
}

function mockOpenRouterDriver(array $responseBody): OpenRouterDriver
{
    Http::fake(['https://openrouter.ai/*' => Http::response($responseBody, 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    return new OpenRouterDriver($embedding, $vectorCache);
}

// ─── analyze() ───────────────────────────────────────────────────────────────

it('returns a parsed narrative from a well-formed OpenRouter response', function () {
    $payload = [
        'choices' => [[
            'message' => [
                'content' => json_encode([
                    'narrative' => 'Critical anomaly detected.',
                    'risk_level' => 'critical',
                    'policy_refs' => ['AML-7'],
                    'confidence' => 0.95,
                ]),
            ],
        ]],
    ];

    $result = mockOpenRouterDriver($payload)->analyze(openRouterAxiom());

    expect($result['narrative'])->toBe('Critical anomaly detected.')
        ->and($result['risk_level'])->toBe('critical')
        ->and($result['policy_refs'])->toBe(['AML-7'])
        ->and($result['confidence'])->toBe(0.95);
});

it('strips markdown code fences before parsing JSON', function () {
    $content = "```json\n".json_encode([
        'narrative' => 'Fenced response.',
        'risk_level' => 'high',
        'policy_refs' => [],
        'confidence' => 0.8,
    ])."\n```";

    $payload = ['choices' => [['message' => ['content' => $content]]]];

    $result = mockOpenRouterDriver($payload)->analyze(openRouterAxiom());

    expect($result['narrative'])->toBe('Fenced response.');
});

it('returns unknown fallback when response shape is unexpected', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'unexpected response shape'));
    Log::shouldReceive('warning')->once()->with('OpenRouterDriver: low quality score', Mockery::type('array'));
    Log::shouldReceive('info')->twice();

    $payload = ['choices' => [['message' => ['content' => 'not json at all']]]];

    $result = mockOpenRouterDriver($payload)->analyze(openRouterAxiom());

    expect($result['narrative'])->toBeNull()
        ->and($result['risk_level'])->toBe('unknown')
        ->and($result['confidence'])->toBe(0.0);
});

it('throws when the OpenRouter API returns a non-2xx status', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response(['error' => 'Unauthorized'], 401)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $driver = new OpenRouterDriver($embedding, $vectorCache);

    expect(fn () => $driver->analyze(openRouterAxiom()))
        ->toThrow(\RuntimeException::class, 'OpenRouterDriver: API call failed');
});

it('proceeds without policy context when RAG throws', function () {
    $payload = [
        'choices' => [[
            'message' => [
                'content' => json_encode([
                    'narrative' => 'No context available.',
                    'risk_level' => 'high',
                    'policy_refs' => [],
                    'confidence' => 0.7,
                ]),
            ],
        ]],
    ];

    Http::fake(['https://openrouter.ai/*' => Http::response($payload, 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andThrow(new \RuntimeException('embedding failed'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');

    $driver = new OpenRouterDriver($embedding, $vectorCache);
    $result = $driver->analyze(openRouterAxiom());

    expect($result['narrative'])->toBe('No context available.');
});

it('sends the Authorization header with the configured API key', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'narrative' => 'OK', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ])]]],
    ], 200)]);

    config(['services.openrouter.api_key' => 'test-key-123']);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom());

    Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer test-key-123'));
});

// ─── Domain filtering ─────────────────────────────────────────────────────────

it('passes domain filter to searchNamespace when domain key is present in data', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'narrative' => 'AML audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
        ])]]],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) =>
            $ns === 'policies' && $filter === "domain = 'aml'"
        )
        ->andReturn([]);

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom(['domain' => 'aml']));
});

it('passes null filter to searchNamespace when domain key is absent', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ])]]],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) =>
            $ns === 'policies' && $filter === null
        )
        ->andReturn([]);

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom());
});

// ─── Output quality scoring ───────────────────────────────────────────────────

function openRouterResponse(array $body): array
{
    return ['choices' => [['message' => ['content' => json_encode($body)]]]];
}

it('logs quality score 4 and no warning for a high-quality response', function () {
    $narrative = str_repeat('Regulatory analysis. ', 8); // 168 chars — above 150 min

    Http::fake(['https://openrouter.ai/*' => Http::response(
        openRouterResponse(['narrative' => $narrative, 'risk_level' => 'high', 'policy_refs' => ['AML-1'], 'confidence' => 0.85]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')->once()->with('OpenRouterDriver: policy RAG retrieval', Mockery::type('array'));
    Log::shouldReceive('info')
        ->once()
        ->with('OpenRouterDriver: response quality', Mockery::on(fn ($ctx) =>
            $ctx['quality_score'] === 4 &&
            $ctx['has_policy_refs'] === true &&
            $ctx['has_risk_level'] === true &&
            $ctx['above_length_min'] === true &&
            $ctx['confidence'] === 0.85
        ));
    Log::shouldReceive('warning')->never();

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom());
});

it('logs quality score 0 and fires a warning when no signals pass', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response(
        openRouterResponse(['narrative' => 'Low.', 'risk_level' => 'unknown', 'policy_refs' => [], 'confidence' => 0.3]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')->once()->with('OpenRouterDriver: policy RAG retrieval', Mockery::type('array'));
    Log::shouldReceive('info')
        ->once()
        ->with('OpenRouterDriver: response quality', Mockery::on(fn ($ctx) =>
            $ctx['quality_score'] === 0
        ));
    Log::shouldReceive('warning')
        ->once()
        ->with('OpenRouterDriver: low quality score', Mockery::on(fn ($ctx) =>
            $ctx['quality_score'] === 0
        ));

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom());
});

it('logs quality score 2 and no warning when exactly two signals pass', function () {
    $narrative = str_repeat('Regulatory analysis. ', 8); // above 150 — length signal passes

    Http::fake(['https://openrouter.ai/*' => Http::response(
        openRouterResponse(['narrative' => $narrative, 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.3]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')->once()->with('OpenRouterDriver: policy RAG retrieval', Mockery::type('array'));
    Log::shouldReceive('info')
        ->once()
        ->with('OpenRouterDriver: response quality', Mockery::on(fn ($ctx) =>
            $ctx['quality_score'] === 2 &&
            $ctx['has_policy_refs'] === false &&
            $ctx['has_risk_level'] === true &&
            $ctx['above_length_min'] === true &&
            $ctx['confidence'] === 0.3
        ));
    Log::shouldReceive('warning')->never();

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom());
});

// ─── Retrieval coverage logging ───────────────────────────────────────────────

it('OpenRouter: logs mean_score and does not flag under_indexed when domain filter returns 2 or more chunks', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response(
        openRouterResponse(['narrative' => str_repeat('Regulatory analysis. ', 8), 'risk_level' => 'high', 'policy_refs' => ['P1'], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([
        ['id' => 'p1', 'score' => 0.85, 'metadata' => []],
        ['id' => 'p2', 'score' => 0.75, 'metadata' => []],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('OpenRouterDriver: policy RAG retrieval', Mockery::on(fn ($ctx) =>
            $ctx['chunk_count'] === 2 &&
            $ctx['mean_score'] === 0.80 &&
            $ctx['under_indexed'] === false
        ));
    Log::shouldReceive('info')->once()->with('OpenRouterDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')->never();

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom(['domain' => 'aml']));
});

it('OpenRouter: fires under-indexed warning when domain is set and fewer than 2 chunks returned', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response(
        openRouterResponse(['narrative' => 'AML audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([
        ['id' => 'p1', 'score' => 0.72, 'metadata' => []],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('OpenRouterDriver: policy RAG retrieval', Mockery::on(fn ($ctx) =>
            $ctx['chunk_count'] === 1 &&
            $ctx['mean_score'] === 0.72 &&
            $ctx['under_indexed'] === true
        ));
    Log::shouldReceive('info')->once()->with('OpenRouterDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')
        ->once()
        ->with('OpenRouterDriver: under-indexed domain', Mockery::on(fn ($ctx) =>
            $ctx['domain'] === 'aml' &&
            $ctx['chunk_count'] === 1 &&
            $ctx['source_id'] === 'sensor-42'
        ));
    Log::shouldReceive('warning')->once()->with('OpenRouterDriver: low quality score', Mockery::type('array'));

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom(['domain' => 'aml']));
});

it('OpenRouter: logs null mean_score and does not fire under-indexed warning when no domain is set', function () {
    Http::fake(['https://openrouter.ai/*' => Http::response(
        openRouterResponse(['narrative' => str_repeat('Regulatory analysis. ', 8), 'risk_level' => 'high', 'policy_refs' => ['P1'], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')
        ->once()
        ->with('OpenRouterDriver: policy RAG retrieval', Mockery::on(fn ($ctx) =>
            $ctx['mean_score'] === null &&
            $ctx['under_indexed'] === false
        ));
    Log::shouldReceive('info')->once()->with('OpenRouterDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')->never();

    (new OpenRouterDriver($embedding, $vectorCache))->analyze(openRouterAxiom());
});
