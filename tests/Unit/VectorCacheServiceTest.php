<?php

use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

// Helper to configure Upstash credentials for every test
beforeEach(function () {
    config([
        'services.upstash_vector.url'                  => 'https://fake-vector.upstash.io',
        'services.upstash_vector.token'                => 'fake-token',
        'services.upstash_vector.similarity_threshold' => 0.95,
    ]);
});

$fakeVector = array_fill(0, 1536, 0.1);

// ─── search ──────────────────────────────────────────────────────────────────

it('returns null when the HTTP request fails', function () use ($fakeVector) {
    Http::fake(['*/query' => Http::response(null, 500)]);

    $result = (new VectorCacheService())->search($fakeVector);

    expect($result)->toBeNull();
});

it('returns null when the result envelope is empty', function () use ($fakeVector) {
    Http::fake(['*/query' => Http::response(['result' => []], 200)]);

    $result = (new VectorCacheService())->search($fakeVector);

    expect($result)->toBeNull();
});

it('returns null when the result key is missing entirely', function () use ($fakeVector) {
    Http::fake(['*/query' => Http::response(['unexpected' => 'shape'], 200)]);

    $result = (new VectorCacheService())->search($fakeVector);

    expect($result)->toBeNull();
});

it('returns null when the best match score is below the threshold', function () use ($fakeVector) {
    Http::fake([
        '*/query' => Http::response([
            'result' => [
                ['id' => 'txn_1', 'score' => 0.89, 'metadata' => ['analysis' => ['isThreat' => false]]],
            ],
        ], 200),
    ]);

    $result = (new VectorCacheService())->search($fakeVector);

    expect($result)->toBeNull();
});

it('returns null when the best match score equals the threshold minus one epsilon', function () use ($fakeVector) {
    Http::fake([
        '*/query' => Http::response([
            'result' => [
                ['id' => 'txn_1', 'score' => 0.9499, 'metadata' => []],
            ],
        ], 200),
    ]);

    expect((new VectorCacheService())->search($fakeVector))->toBeNull();
});

it('returns the top result when score meets the threshold exactly', function () use ($fakeVector) {
    $match = ['id' => 'txn_abc', 'score' => 0.95, 'metadata' => ['analysis' => ['isThreat' => false, 'message' => 'OK']]];

    Http::fake([
        '*/query' => Http::response(['result' => [$match]], 200),
    ]);

    $result = (new VectorCacheService())->search($fakeVector);

    expect($result)->toBe($match);
});

it('returns the top result when score exceeds the threshold', function () use ($fakeVector) {
    $best   = ['id' => 'txn_1', 'score' => 0.99, 'metadata' => ['analysis' => ['isThreat' => true]]];
    $second = ['id' => 'txn_2', 'score' => 0.96, 'metadata' => ['analysis' => ['isThreat' => false]]];

    Http::fake([
        '*/query' => Http::response(['result' => [$best, $second]], 200),
    ]);

    $result = (new VectorCacheService())->search($fakeVector);

    expect($result['id'])->toBe('txn_1');
});

it('sends the correct query payload', function () use ($fakeVector) {
    Http::fake(['*/query' => Http::response(['result' => []], 200)]);

    (new VectorCacheService())->search($fakeVector, topK: 5);

    Http::assertSent(function ($request) use ($fakeVector) {
        $body = $request->data();
        return $body['vector'] === $fakeVector
            && $body['topK'] === 5
            && $body['includeMetadata'] === true;
    });
});

it('sends the Bearer token in the Authorization header', function () use ($fakeVector) {
    Http::fake(['*/query' => Http::response(['result' => []], 200)]);

    (new VectorCacheService())->search($fakeVector);

    Http::assertSent(function ($request) {
        return $request->header('Authorization')[0] === 'Bearer fake-token';
    });
});

// ─── upsert ──────────────────────────────────────────────────────────────────

it('returns true when the upsert succeeds', function () use ($fakeVector) {
    Http::fake(['*/upsert' => Http::response([], 200)]);

    $result = (new VectorCacheService())->upsert('txn_1', $fakeVector, ['analysis' => []]);

    expect($result)->toBeTrue();
});

it('returns false when the upsert request fails', function () use ($fakeVector) {
    Http::fake(['*/upsert' => Http::response(['error' => 'bad request'], 400)]);

    $result = (new VectorCacheService())->upsert('txn_1', $fakeVector, ['analysis' => []]);

    expect($result)->toBeFalse();
});

it('sends id, vector, and metadata in the upsert payload', function () use ($fakeVector) {
    Http::fake(['*/upsert' => Http::response([], 200)]);

    $metadata = ['analysis' => ['isThreat' => false], 'threat_level' => 'low'];

    (new VectorCacheService())->upsert('txn_xyz', $fakeVector, $metadata);

    Http::assertSent(function ($request) use ($fakeVector, $metadata) {
        $body = $request->data();
        // Upstash upsert expects an array of objects
        return isset($body[0])
            && $body[0]['id'] === 'txn_xyz'
            && $body[0]['vector'] === $fakeVector
            && $body[0]['metadata'] === $metadata;
    });
});
