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
    Log::shouldReceive('info')->once();

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
