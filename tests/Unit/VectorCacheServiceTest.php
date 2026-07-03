<?php

use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

// Helper to configure Upstash credentials for every test
beforeEach(function () {
    config([
        'services.upstash_vector.url' => 'https://fake-vector.upstash.io',
        'services.upstash_vector.token' => 'fake-token',
        'services.upstash_vector.similarity_threshold' => 0.95,
    ]);
});

$fakeVector = array_fill(0, 1536, 0.1);

// ─── searchNamespace (transactions) ───────────────────────────────────────────

it('returns empty array when the HTTP request fails', function () use ($fakeVector) {
    Http::fake(['*/query/transactions' => Http::response(null, 500)]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result)->toBe([]);
});

it('returns empty array when the result envelope is empty', function () use ($fakeVector) {
    Http::fake(['*/query/transactions' => Http::response(['result' => []], 200)]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result)->toBe([]);
});

it('returns empty array when the result key is missing entirely', function () use ($fakeVector) {
    Http::fake(['*/query/transactions' => Http::response(['unexpected' => 'shape'], 200)]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result)->toBe([]);
});

it('filters out the best match when its score is below the threshold', function () use ($fakeVector) {
    Http::fake([
        '*/query/transactions' => Http::response([
            'result' => [
                ['id' => 'txn_1', 'score' => 0.89, 'metadata' => ['analysis' => ['isThreat' => false]]],
            ],
        ], 200),
    ]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result)->toBe([]);
});

it('filters out the best match when its score equals the threshold minus one epsilon', function () use ($fakeVector) {
    Http::fake([
        '*/query/transactions' => Http::response([
            'result' => [
                ['id' => 'txn_1', 'score' => 0.9499, 'metadata' => []],
            ],
        ], 200),
    ]);

    expect((new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1))->toBe([]);
});

it('returns the top result when score meets the threshold exactly', function () use ($fakeVector) {
    Http::fake([
        '*/query/transactions' => Http::response([
            'result' => [
                ['id' => 'txn_abc', 'score' => 0.95, 'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'OK']]],
            ],
        ], 200),
    ]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result)->toHaveCount(1)
        ->and($result[0]['id'])->toBe('txn_abc');
});

it('returns the top result first when multiple scores exceed the threshold', function () use ($fakeVector) {
    Http::fake([
        '*/query/transactions' => Http::response([
            'result' => [
                ['id' => 'txn_1', 'score' => 0.99, 'metadata' => ['analysis' => ['isThreat' => true]]],
                ['id' => 'txn_2', 'score' => 0.96, 'metadata' => ['analysis' => ['isThreat' => false]]],
            ],
        ], 200),
    ]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 3);

    expect($result[0]['id'])->toBe('txn_1');
});

it('sends the correct query payload', function () use ($fakeVector) {
    Http::fake(['*/query/transactions' => Http::response(['result' => []], 200)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 5);

    Http::assertSent(function ($request) use ($fakeVector) {
        $body = $request->data();

        return $body['vector'] === $fakeVector
            && $body['topK'] === 5
            && $body['includeMetadata'] === true;
    });
});

it('sends the Bearer token in the Authorization header', function () use ($fakeVector) {
    Http::fake(['*/query/transactions' => Http::response(['result' => []], 200)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    Http::assertSent(function ($request) {
        return $request->header('Authorization')[0] === 'Bearer fake-token';
    });
});

// ─── searchNamespace: retry behaviour ─────────────────────────────────────────

it('retries search on transient failure and succeeds', function () use ($fakeVector) {
    Http::fake([
        '*/query/transactions' => Http::sequence()
            ->push(null, 503)
            ->push(['result' => [
                ['id' => 'txn_retry', 'score' => 0.98, 'metadata' => ['analysis' => ['isThreat' => false]]],
            ]], 200),
    ]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result[0]['id'])->toBe('txn_retry');
    Http::assertSentCount(2);
});

it('returns empty array after search retries are exhausted', function () use ($fakeVector) {
    Http::fake([
        '*/query/transactions' => Http::sequence()
            ->push(null, 502)
            ->push(null, 502),
    ]);

    $result = (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);

    expect($result)->toBe([]);
});

// ─── searchNamespace: logging ─────────────────────────────────────────────────

it('logs a warning when search fails', function () use ($fakeVector) {
    Log::shouldReceive('warning')
        ->once()
        ->with('Vector namespace search failed', Mockery::on(function ($ctx) {
            return $ctx['namespace'] === 'transactions' && isset($ctx['status']);
        }));

    Http::fake(['*/query/transactions' => Http::response(['error' => 'bad'], 500)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);
});

it('does not log a warning when search succeeds', function () use ($fakeVector) {
    Log::shouldReceive('warning')->never();

    Http::fake(['*/query/transactions' => Http::response(['result' => []], 200)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'transactions', 0.95, 1);
});

// ─── upsertNamespace (transactions) ───────────────────────────────────────────

it('returns true when the upsert succeeds', function () use ($fakeVector) {
    Http::fake(['*/upsert/transactions' => Http::response([], 200)]);

    $result = (new VectorCacheService)->upsertNamespace('txn_1', $fakeVector, ['analysis' => []], 'transactions');

    expect($result)->toBeTrue();
});

it('returns false when the upsert request fails', function () use ($fakeVector) {
    Http::fake(['*/upsert/transactions' => Http::response(['error' => 'bad request'], 400)]);

    $result = (new VectorCacheService)->upsertNamespace('txn_1', $fakeVector, ['analysis' => []], 'transactions');

    expect($result)->toBeFalse();
});

it('sends id, vector, and metadata in the upsert payload', function () use ($fakeVector) {
    Http::fake(['*/upsert/transactions' => Http::response([], 200)]);

    $metadata = ['analysis' => ['isThreat' => false], 'threat_level' => 'low'];

    (new VectorCacheService)->upsertNamespace('txn_xyz', $fakeVector, $metadata, 'transactions');

    Http::assertSent(function ($request) use ($fakeVector, $metadata) {
        $body = $request->data();

        // Upstash upsert expects an array of objects
        return isset($body[0])
            && $body[0]['id'] === 'txn_xyz'
            && $body[0]['vector'] === $fakeVector
            && $body[0]['metadata'] === $metadata;
    });
});

// ─── upsertNamespace: retry behaviour ─────────────────────────────────────────

it('retries upsert on transient failure and succeeds', function () use ($fakeVector) {
    Http::fake([
        '*/upsert/transactions' => Http::sequence()
            ->push(null, 503)
            ->push([], 200),
    ]);

    $result = (new VectorCacheService)->upsertNamespace('txn_retry', $fakeVector, ['test' => true], 'transactions');

    expect($result)->toBeTrue();
    Http::assertSentCount(2);
});

it('returns false after upsert retries are exhausted', function () use ($fakeVector) {
    Http::fake([
        '*/upsert/transactions' => Http::sequence()
            ->push(null, 500)
            ->push(null, 500),
    ]);

    $result = (new VectorCacheService)->upsertNamespace('txn_fail', $fakeVector, ['test' => true], 'transactions');

    expect($result)->toBeFalse();
});

// ─── upsertNamespace: logging ─────────────────────────────────────────────────

it('logs a warning when upsert fails', function () use ($fakeVector) {
    Log::shouldReceive('warning')
        ->once()
        ->with('Vector namespace upsert failed', Mockery::on(function ($ctx) {
            return $ctx['namespace'] === 'transactions'
                && $ctx['id'] === 'txn_log'
                && isset($ctx['status'])
                && isset($ctx['body']);
        }));

    Http::fake(['*/upsert/transactions' => Http::response(['error' => 'quota'], 429)]);

    (new VectorCacheService)->upsertNamespace('txn_log', $fakeVector, ['test' => true], 'transactions');
});

it('does not log a warning when upsert succeeds', function () use ($fakeVector) {
    Log::shouldReceive('warning')->never();

    Http::fake(['*/upsert/transactions' => Http::response([], 200)]);

    (new VectorCacheService)->upsertNamespace('txn_ok', $fakeVector, ['test' => true], 'transactions');
});

// ─── deleteNamespace (transactions) ───────────────────────────────────────────

it('returns true when delete succeeds', function () {
    Http::fake(['*/delete/transactions' => Http::response([], 200)]);

    $result = (new VectorCacheService)->deleteNamespace('txn_old', 'transactions');

    expect($result)->toBeTrue();
});

it('returns false when delete fails', function () {
    Http::fake(['*/delete/transactions' => Http::response(['error' => 'not found'], 404)]);

    $result = (new VectorCacheService)->deleteNamespace('txn_unknown', 'transactions');

    expect($result)->toBeFalse();
});

it('sends the correct delete payload', function () {
    Http::fake(['*/delete/transactions' => Http::response([], 200)]);

    (new VectorCacheService)->deleteNamespace('txn_cleanup', 'transactions');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), '/delete/transactions')
            && in_array('txn_cleanup', $request->data());
    });
});

it('sends the Bearer token in delete requests', function () {
    Http::fake(['*/delete/transactions' => Http::response([], 200)]);

    (new VectorCacheService)->deleteNamespace('txn_auth', 'transactions');

    Http::assertSent(function ($request) {
        return $request->header('Authorization')[0] === 'Bearer fake-token';
    });
});

it('retries delete on transient failure and succeeds', function () {
    Http::fake([
        '*/delete/transactions' => Http::sequence()
            ->push(null, 502)
            ->push([], 200),
    ]);

    $result = (new VectorCacheService)->deleteNamespace('txn_retry_del', 'transactions');

    expect($result)->toBeTrue();
    Http::assertSentCount(2);
});

it('logs a warning when delete fails', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Vector namespace delete failed', Mockery::on(function ($ctx) {
            return $ctx['namespace'] === 'transactions'
                && $ctx['id'] === 'txn_del_fail'
                && isset($ctx['status']);
        }));

    Http::fake(['*/delete/transactions' => Http::response(['error' => 'err'], 500)]);

    (new VectorCacheService)->deleteNamespace('txn_del_fail', 'transactions');
});

// ─── namespace endpoint paths ─────────────────────────────────────────────────

it('posts searchNamespace to {baseUrl}/query/{namespace}', function () use ($fakeVector) {
    Http::fake(['*' => Http::response(['result' => []], 200)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'policies', 0.70, 3);

    Http::assertSent(function ($request) {
        return (string) $request->url() === 'https://fake-vector.upstash.io/query/policies';
    });
});

it('posts upsertNamespace to {baseUrl}/upsert/{namespace}', function () use ($fakeVector) {
    Http::fake(['*' => Http::response([], 200)]);

    (new VectorCacheService)->upsertNamespace('policy_0', $fakeVector, ['text' => 'x'], 'policies');

    Http::assertSent(function ($request) {
        return (string) $request->url() === 'https://fake-vector.upstash.io/upsert/policies';
    });
});

it('returns true from upsertNamespace on a successful response', function () use ($fakeVector) {
    Http::fake(['*/upsert/policies' => Http::response([], 200)]);

    $result = (new VectorCacheService)->upsertNamespace('policy_0', $fakeVector, ['text' => 'x'], 'policies');

    expect($result)->toBeTrue();
});

it('returns false from upsertNamespace on a failed response', function () use ($fakeVector) {
    Http::fake(['*/upsert/policies' => Http::response(['error' => 'not found'], 404)]);

    $result = (new VectorCacheService)->upsertNamespace('policy_0', $fakeVector, ['text' => 'x'], 'policies');

    expect($result)->toBeFalse();
});

// ─── searchNamespace: filter parameter ───────────────────────────────────────

it('sends filter in searchNamespace payload when filter is provided', function () use ($fakeVector) {
    Http::fake(['*/query/policies' => Http::response(['result' => []], 200)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'policies', 0.70, 3, "domain = 'aml'");

    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['filter']) && $body['filter'] === "domain = 'aml'";
    });
});

it('omits filter key from searchNamespace payload when filter is null', function () use ($fakeVector) {
    Http::fake(['*/query/policies' => Http::response(['result' => []], 200)]);

    (new VectorCacheService)->searchNamespace($fakeVector, 'policies', 0.70, 3);

    Http::assertSent(function ($request) {
        return ! array_key_exists('filter', $request->data());
    });
});

it('returns only chunks above threshold when filter is applied', function () use ($fakeVector) {
    Http::fake([
        '*/query/policies' => Http::response([
            'result' => [
                ['id' => 'aml_0', 'score' => 0.85, 'metadata' => ['domain' => 'aml', 'text' => 'AML rule']],
                ['id' => 'aml_1', 'score' => 0.60, 'metadata' => ['domain' => 'aml', 'text' => 'Below threshold']],
            ],
        ], 200),
    ]);

    $results = (new VectorCacheService)->searchNamespace($fakeVector, 'policies', 0.70, 3, "domain = 'aml'");

    expect($results)->toHaveCount(1)
        ->and($results[0]['id'])->toBe('aml_0');
});
