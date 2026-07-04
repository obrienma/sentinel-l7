<?php

use App\Services\Compliance\OllamaDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function ollamaAxiom(array $overrides = []): array
{
    return array_merge([
        'status' => 'critical',
        'metric_value' => 94.0,
        'anomaly_score' => 0.91,
        'source_id' => 'sensor-42',
        'emitted_at' => '2026-04-01T10:00:00Z',
    ], $overrides);
}

function ollamaResponse(array $body): array
{
    return [
        'message' => ['role' => 'assistant', 'content' => json_encode($body)],
    ];
}

function mockOllamaDriver(array $responseBody): OllamaDriver
{
    Http::fake(['*/api/chat' => Http::response($responseBody, 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    return new OllamaDriver($embedding, $vectorCache);
}

// ─── analyze() ───────────────────────────────────────────────────────────────

it('returns a parsed narrative from a well-formed Ollama response', function () {
    $result = mockOllamaDriver(ollamaResponse([
        'narrative' => 'AML violation detected.',
        'risk_level' => 'critical',
        'policy_refs' => ['AML-12'],
        'confidence' => 0.97,
    ]))->analyze(ollamaAxiom());

    expect($result['narrative'])->toBe('AML violation detected.')
        ->and($result['risk_level'])->toBe('critical')
        ->and($result['confidence'])->toBe(0.97);
});

it('sends stream=false, format=json, and think=false in the request body', function () {
    $captured = null;

    Http::fake(function ($request) use (&$captured) {
        $captured = $request->data();

        return Http::response(ollamaResponse([
            'narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ]), 200);
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());

    expect($captured['stream'])->toBeFalse()
        ->and($captured['format'])->toBe('json')
        ->and($captured['think'])->toBeFalse()
        ->and($captured['messages'][0]['role'])->toBe('user');
});

it('strips markdown code fences before parsing JSON', function () {
    $inner = json_encode(['narrative' => 'Fenced.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.8]);

    Http::fake(['*/api/chat' => Http::response([
        'message' => ['role' => 'assistant', 'content' => "```json\n{$inner}\n```"],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $result = (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());

    expect($result['narrative'])->toBe('Fenced.');
});

it('returns unknown fallback when response shape is unexpected', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'unexpected response shape'));
    Log::shouldReceive('warning')
        ->once()
        ->with('OllamaDriver: low quality score', Mockery::type('array'));
    Log::shouldReceive('info')->twice();

    Http::fake(['*/api/chat' => Http::response([
        'message' => ['role' => 'assistant', 'content' => 'not json'],
    ], 200)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    $result = (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());

    expect($result['narrative'])->toBeNull()
        ->and($result['risk_level'])->toBe('unknown');
});

it('throws when the Ollama API returns a non-2xx status', function () {
    Http::fake(['*/api/chat' => Http::response(['error' => 'model not found'], 404)]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    expect(fn () => (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom()))
        ->toThrow(\RuntimeException::class, 'OllamaDriver: API call failed');
});

it('proceeds without policy context when RAG embedding throws', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'No context.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.7]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->once()->andThrow(new \RuntimeException('embedding failed'));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldNotReceive('searchNamespace');

    $result = (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());

    expect($result)->toBeArray();
});

// ─── Domain filtering ─────────────────────────────────────────────────────────

it('passes domain filter to searchNamespace when domain key is present in data', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'AML audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) => $ns === 'policies' && $filter === "domain = 'aml'"
        )
        ->andReturn([]);

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom(['domain' => 'aml']));
});

it('passes null filter to searchNamespace when domain key is absent', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) => $ns === 'policies' && $filter === null
        )
        ->andReturn([]);

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());
});

// ─── Retrieval logging ────────────────────────────────────────────────────────

it('logs retrieval info with domain and filter_used true when domain is present', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([
        ['id' => 'aml_0', 'score' => 0.88, 'metadata' => []],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: policy RAG retrieval', Mockery::on(fn ($ctx) => $ctx['domain'] === 'aml' &&
            $ctx['filter_used'] === true &&
            $ctx['chunk_count'] === 1 &&
            $ctx['mean_score'] === 0.88 &&
            $ctx['under_indexed'] === true &&
            $ctx['scores'] === [0.88]
        ));
    Log::shouldReceive('info')->once()->with('OllamaDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')
        ->once()
        ->with('OllamaDriver: under-indexed domain', Mockery::on(fn ($ctx) => $ctx['domain'] === 'aml' &&
            $ctx['chunk_count'] === 1 &&
            $ctx['source_id'] === 'sensor-42'
        ));
    Log::shouldReceive('warning')->once()->with('OllamaDriver: low quality score', Mockery::type('array'));

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom(['domain' => 'aml']));
});

it('logs retrieval info with null domain and filter_used false when domain is absent', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: policy RAG retrieval', Mockery::on(fn ($ctx) => $ctx['domain'] === null &&
            $ctx['filter_used'] === false &&
            $ctx['chunk_count'] === 0 &&
            $ctx['mean_score'] === null &&
            $ctx['under_indexed'] === false
        ));
    Log::shouldReceive('info')->once()->with('OllamaDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')->once()->with('OllamaDriver: low quality score', Mockery::type('array'));

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());
});

// ─── Output quality scoring ───────────────────────────────────────────────────

it('logs quality score 4 and no warning for a high-quality response', function () {
    $narrative = str_repeat('Regulatory analysis. ', 8); // 168 chars — above 150 min

    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => $narrative, 'risk_level' => 'high', 'policy_refs' => ['AML-1'], 'confidence' => 0.85]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')->once()->with('OllamaDriver: policy RAG retrieval', Mockery::type('array'));
    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: response quality', Mockery::on(fn ($ctx) => $ctx['quality_score'] === 4 &&
            $ctx['has_policy_refs'] === true &&
            $ctx['has_risk_level'] === true &&
            $ctx['above_length_min'] === true &&
            $ctx['confidence'] === 0.85
        ));
    Log::shouldReceive('warning')->never();

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());
});

it('logs quality score 0 and fires a warning when no signals pass', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'Low.', 'risk_level' => 'unknown', 'policy_refs' => [], 'confidence' => 0.3]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')->once()->with('OllamaDriver: policy RAG retrieval', Mockery::type('array'));
    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: response quality', Mockery::on(fn ($ctx) => $ctx['quality_score'] === 0
        ));
    Log::shouldReceive('warning')
        ->once()
        ->with('OllamaDriver: low quality score', Mockery::on(fn ($ctx) => $ctx['quality_score'] === 0
        ));

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());
});

it('logs quality score 2 and no warning when exactly two signals pass', function () {
    $narrative = str_repeat('Regulatory analysis. ', 8); // above 150 — length signal passes

    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => $narrative, 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.3]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')->once()->with('OllamaDriver: policy RAG retrieval', Mockery::type('array'));
    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: response quality', Mockery::on(fn ($ctx) => $ctx['quality_score'] === 2 &&
            $ctx['has_policy_refs'] === false &&
            $ctx['has_risk_level'] === true &&
            $ctx['above_length_min'] === true &&
            $ctx['confidence'] === 0.3
        ));
    Log::shouldReceive('warning')->never();

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());
});

// ─── analyzeTransaction() ──────────────────────────────────────────────────────

function ollamaTransaction(array $overrides = []): array
{
    return array_merge([
        'id' => 'txn-42',
        'merchant' => 'ACME Corp',
        'amount' => 500.00,
        'currency' => 'AUD',
    ], $overrides);
}

it('returns a parsed narrative from a well-formed transaction analysis response', function () {
    $result = mockOllamaDriver(ollamaResponse([
        'narrative' => 'High value transaction requires review.',
        'risk_level' => 'high',
        'policy_refs' => ['AML-3'],
        'confidence' => 0.88,
    ]))->analyzeTransaction(ollamaTransaction());

    expect($result['narrative'])->toBe('High value transaction requires review.')
        ->and($result['risk_level'])->toBe('high')
        ->and($result['confidence'])->toBe(0.88);
});

it('builds the transaction prompt from the transaction-compliance-analysis template', function () {
    $captured = null;

    Http::fake(function ($request) use (&$captured) {
        $captured = $request->data()['messages'][0]['content'];

        return Http::response(ollamaResponse([
            'narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5,
        ]), 200);
    });

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    (new OllamaDriver($embedding, $vectorCache))->analyzeTransaction(ollamaTransaction());

    expect($captured)
        ->toContain('Merchant: ACME Corp')
        ->toContain('Amount: 500')
        ->toContain('Currency: AUD')
        ->not->toContain('Anomaly score');
});

it('does not filter by domain for a transaction (transactions carry no domain)', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => 'OK.', 'risk_level' => 'low', 'policy_refs' => [], 'confidence' => 0.5]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')
        ->once()
        ->withArgs(fn ($vec, $ns, $threshold, $topK, $filter) => $ns === 'policies' && $filter === null)
        ->andReturn([]);

    (new OllamaDriver($embedding, $vectorCache))->analyzeTransaction(ollamaTransaction());
});

// ─── Retrieval coverage logging ───────────────────────────────────────────────

it('logs mean_score and does not flag under_indexed when domain filter returns 2 or more chunks', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => str_repeat('Regulatory analysis. ', 8), 'risk_level' => 'high', 'policy_refs' => ['P1'], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([
        ['id' => 'p1', 'score' => 0.85, 'metadata' => []],
        ['id' => 'p2', 'score' => 0.75, 'metadata' => []],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: policy RAG retrieval', Mockery::on(fn ($ctx) => $ctx['chunk_count'] === 2 &&
            $ctx['mean_score'] === 0.80 &&
            $ctx['under_indexed'] === false
        ));
    Log::shouldReceive('info')->once()->with('OllamaDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')->never();

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom(['domain' => 'aml']));
});

it('logs null mean_score and does not fire under-indexed warning when no domain is set', function () {
    Http::fake(['*/api/chat' => Http::response(
        ollamaResponse(['narrative' => str_repeat('Regulatory analysis. ', 8), 'risk_level' => 'high', 'policy_refs' => ['P1'], 'confidence' => 0.9]),
        200
    )]);

    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 768, 0.1));

    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('searchNamespace')->andReturn([]);

    Log::shouldReceive('info')
        ->once()
        ->with('OllamaDriver: policy RAG retrieval', Mockery::on(fn ($ctx) => $ctx['mean_score'] === null &&
            $ctx['under_indexed'] === false
        ));
    Log::shouldReceive('info')->once()->with('OllamaDriver: response quality', Mockery::type('array'));
    Log::shouldReceive('warning')->never();

    (new OllamaDriver($embedding, $vectorCache))->analyze(ollamaAxiom());
});
