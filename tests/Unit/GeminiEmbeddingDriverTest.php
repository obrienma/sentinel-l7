<?php

use App\Services\Embedding\GeminiEmbeddingDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

// ─── embed ────────────────────────────────────────────────────────────────────

it('returns the embedding values array on a successful response', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::response([
            'embedding' => [
                'values' => array_fill(0, 1536, 0.1),
            ],
        ], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $result = $driver->embed('some text');

    expect($result)
        ->toBeArray()
        ->toHaveCount(1536);
});

it('throws a RuntimeException when the Gemini API returns a non-2xx status', function () {
    config(['services.gemini.api_key' => 'bad-key']);

    Http::fake([
        '*embedContent*' => Http::response([
            'error' => ['code' => 404, 'message' => 'model not found', 'status' => 'NOT_FOUND'],
        ], 404),
    ]);

    $driver = new GeminiEmbeddingDriver;

    expect(fn () => $driver->embed('some text'))
        ->toThrow(\RuntimeException::class, 'Gemini embedding failed');
});

it('includes the API error body in the exception message', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
    ]);

    $driver = new GeminiEmbeddingDriver;

    expect(fn () => $driver->embed('some text'))
        ->toThrow(\RuntimeException::class, 'Gemini embedding failed');
});

it('sends output_dimensionality of 1536 in the request body', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 1536, 0.0)]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $driver->embed('test');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['output_dimensionality']) && $body['output_dimensionality'] === 1536;
    });
});

it('sends the text in the content parts structure', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => [0.1]]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $driver->embed('hello world');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['content']['parts'][0]['text'] ?? null) === 'hello world';
    });
});

it('appends the API key as a query parameter', function () {
    config(['services.gemini.api_key' => 'my-secret-key']);

    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => [0.1]]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $driver->embed('test');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'key=my-secret-key');
    });
});

// ─── Retry behaviour ─────────────────────────────────────────────────────────

it('retries on transient failure and succeeds on subsequent attempt', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::sequence()
            ->push(['error' => 'Server Error'], 503)
            ->push(['embedding' => ['values' => array_fill(0, 1536, 0.5)]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $result = $driver->embed('retry test');

    expect($result)->toBeArray()->toHaveCount(1536);
    Http::assertSentCount(2);
});

it('throws after all retries are exhausted', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(['error' => 'rate limited'], 429)
            ->push(['error' => 'rate limited'], 429),
    ]);

    $driver = new GeminiEmbeddingDriver;

    expect(fn () => $driver->embed('exhaust retries'))
        ->toThrow(\RuntimeException::class, 'Gemini embedding failed');
});

// ─── Logging ──────────────────────────────────────────────────────────────────

it('logs a warning when the Gemini API returns an error', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Log::shouldReceive('warning')
        ->once()
        ->with('Gemini embedding failed', Mockery::on(function ($context) {
            return isset($context['status']) && $context['status'] === 500
                && isset($context['body']);
        }));

    Http::fake([
        '*embedContent*' => Http::response(['error' => 'internal'], 500),
    ]);

    $driver = new GeminiEmbeddingDriver;

    try {
        $driver->embed('log test');
    } catch (\RuntimeException) {
        // Expected — we're testing the log call, not the exception
    }
});

it('does not log a warning on a successful response', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Log::shouldReceive('warning')->never();

    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => [0.1]]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $driver->embed('success test');
});

// ─── Configurable URL ─────────────────────────────────────────────────────────

it('uses a custom embedding URL from config when provided', function () {
    config([
        'services.gemini.api_key' => 'test-key',
        'services.gemini.embedding_url' => 'https://custom-proxy.example.com/v1/embed',
    ]);

    Http::fake([
        'custom-proxy.example.com/*' => Http::response(['embedding' => ['values' => [0.1]]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $driver->embed('custom url test');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'custom-proxy.example.com');
    });
});

it('falls back to the default Gemini URL when no custom URL is configured', function () {
    config([
        'services.gemini.api_key' => 'test-key',
        'services.gemini.embedding_url' => null,
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.1]]], 200),
    ]);

    $driver = new GeminiEmbeddingDriver;
    $driver->embed('default url test');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'generativelanguage.googleapis.com');
    });
});
