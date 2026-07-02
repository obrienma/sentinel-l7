<?php

use App\Contracts\EmbeddingDriver;
use App\Services\Embedding\OllamaEmbeddingDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'services.ollama.url' => 'http://localhost:11434',
        'services.ollama.embedding_model' => 'nomic-embed-text',
        'services.ollama.timeout' => 10,
    ]);
});

it('returns the embedding array on a successful response', function () {
    Http::fake([
        '*/api/embeddings' => Http::response(['embedding' => array_fill(0, 768, 0.1)], 200),
    ]);

    $driver = new OllamaEmbeddingDriver;
    $result = $driver->embed('some text');

    expect($result)->toBeArray()->toHaveCount(768);
});

it('prefixes the prompt with search_document by default', function () {
    Http::fake([
        '*/api/embeddings' => Http::response(['embedding' => [0.1]], 200),
    ]);

    $driver = new OllamaEmbeddingDriver;
    $driver->embed('hello world');

    Http::assertSent(function ($request) {
        return ($request->data()['prompt'] ?? null) === 'search_document: hello world';
    });
});

it('prefixes the prompt with search_query when the query task is given', function () {
    Http::fake([
        '*/api/embeddings' => Http::response(['embedding' => [0.1]], 200),
    ]);

    $driver = new OllamaEmbeddingDriver;
    $driver->embed('what is the AML threshold?', EmbeddingDriver::TASK_QUERY);

    Http::assertSent(function ($request) {
        return ($request->data()['prompt'] ?? null) === 'search_query: what is the AML threshold?';
    });
});

it('sends the configured model name', function () {
    config(['services.ollama.embedding_model' => 'nomic-embed-text:v1.5']);

    Http::fake([
        '*/api/embeddings' => Http::response(['embedding' => [0.1]], 200),
    ]);

    $driver = new OllamaEmbeddingDriver;
    $driver->embed('test');

    Http::assertSent(function ($request) {
        return ($request->data()['model'] ?? null) === 'nomic-embed-text:v1.5';
    });
});

it('posts to the configured Ollama base URL', function () {
    config(['services.ollama.url' => 'http://ollama.internal:11434']);

    Http::fake([
        'ollama.internal:11434/*' => Http::response(['embedding' => [0.1]], 200),
    ]);

    $driver = new OllamaEmbeddingDriver;
    $driver->embed('test');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'ollama.internal:11434/api/embeddings');
    });
});

it('throws a RuntimeException when Ollama returns a non-2xx status', function () {
    Http::fake([
        '*/api/embeddings' => Http::response(['error' => 'model not found'], 404),
    ]);

    $driver = new OllamaEmbeddingDriver;

    expect(fn () => $driver->embed('some text'))
        ->toThrow(\RuntimeException::class, 'Ollama embedding failed');
});

it('logs a warning when Ollama returns an error', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Ollama embedding failed', Mockery::on(function ($context) {
            return isset($context['status']) && $context['status'] === 500
                && isset($context['body']);
        }));

    Http::fake([
        '*/api/embeddings' => Http::response(['error' => 'internal'], 500),
    ]);

    $driver = new OllamaEmbeddingDriver;

    try {
        $driver->embed('log test');
    } catch (\RuntimeException) {
        // Expected — we're testing the log call, not the exception
    }
});

it('retries on transient failure and succeeds on subsequent attempt', function () {
    Http::fake([
        '*/api/embeddings' => Http::sequence()
            ->push(['error' => 'Server Error'], 503)
            ->push(['embedding' => array_fill(0, 768, 0.5)], 200),
    ]);

    $driver = new OllamaEmbeddingDriver;
    $result = $driver->embed('retry test');

    expect($result)->toBeArray()->toHaveCount(768);
    Http::assertSentCount(2);
});
