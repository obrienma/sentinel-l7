<?php

use App\Services\EmbeddingService;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

// ─── createTransactionFingerprint ────────────────────────────────────────────

it('builds a fingerprint with all fields present', function () {
    $service = new EmbeddingService();

    $fingerprint = $service->createTransactionFingerprint([
        'amount'        => '12.50',
        'currency'      => 'CAD',
        'type'          => 'purchase',
        'category'      => 'coffee',
        'timestamp'     => '2026-01-01T09:14:00+00:00',
        'merchant_name' => 'Starbucks',
    ]);

    expect($fingerprint)
        ->toContain('Amount: 12.50 CAD')
        ->toContain('Type: purchase')
        ->toContain('Category: coffee')
        ->toContain('Merchant: Starbucks')
        ->toContain('Time: 09:14');
});

it('uses N/A for missing amount and currency', function () {
    $service = new EmbeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)
        ->toContain('Amount: N/A N/A');
});

it('defaults category to "unknown" when absent', function () {
    $service = new EmbeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)->toContain('Category: unknown');
});

it('uses N/A for merchant when absent', function () {
    $service = new EmbeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)->toContain('Merchant: N/A');
});

it('uses N/A for time when timestamp is absent', function () {
    $service = new EmbeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)->toContain('Time: N/A');
});

it('pipe-delimits the fingerprint fields', function () {
    $service = new EmbeddingService();
    $fingerprint = $service->createTransactionFingerprint([
        'amount'   => '5.00',
        'currency' => 'USD',
    ]);

    expect(substr_count($fingerprint, ' | '))->toBe(4);
});

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

    $service = new EmbeddingService();
    $result = $service->embed('some text');

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

    $service = new EmbeddingService();

    expect(fn() => $service->embed('some text'))
        ->toThrow(\RuntimeException::class, 'Gemini embedding failed');
});

it('includes the API error body in the exception message', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
    ]);

    $service = new EmbeddingService();

    expect(fn() => $service->embed('some text'))
        ->toThrow(\RuntimeException::class, 'Gemini embedding failed');
});

it('sends output_dimensionality of 1536 in the request body', function () {
    config(['services.gemini.api_key' => 'test-key']);

    Http::fake([
        '*embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 1536, 0.0)]], 200),
    ]);

    $service = new EmbeddingService();
    $service->embed('test');

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

    $service = new EmbeddingService();
    $service->embed('hello world');

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

    $service = new EmbeddingService();
    $service->embed('test');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'key=my-secret-key');
    });
});
