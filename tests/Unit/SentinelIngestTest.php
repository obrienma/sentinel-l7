<?php

use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Artisan;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'services.gemini.api_key'                      => 'test-key',
        'services.upstash_vector.url'                  => 'https://fake-vector.upstash.io',
        'services.upstash_vector.token'                => 'fake-token',
        'services.upstash_vector.similarity_threshold' => 0.70,
    ]);
});

it('derives aml domain from aml-bsa-compliance filename', function () {
    $filename = 'aml-bsa-compliance';
    $domain   = explode('-', $filename)[0];

    expect($domain)->toBe('aml');
});

it('derives gdpr domain from gdpr-data-processing filename', function () {
    $filename = 'gdpr-data-processing';
    $domain   = explode('-', $filename)[0];

    expect($domain)->toBe('gdpr');
});

it('includes domain key in the metadata passed to upsertNamespace', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $this->instance(EmbeddingService::class, $embedding);

    $missing = [];
    $vectorCache = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('upsertNamespace')
        ->andReturnUsing(function ($id, $vec, $metadata, $ns) use (&$missing) {
            if (!array_key_exists('domain', $metadata)) {
                $missing[] = $id;
            }
            return true;
        });
    $this->instance(VectorCacheService::class, $vectorCache);

    Artisan::call('sentinel:ingest', ['--path' => 'policies']);

    expect($missing)->toBeEmpty('chunks missing domain tag: ' . implode(', ', $missing));
});

it('tags each chunk with the domain derived from the filename', function () {
    $embedding = Mockery::mock(EmbeddingService::class);
    $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $this->instance(EmbeddingService::class, $embedding);

    $capturedMetadata = [];
    $vectorCache      = Mockery::mock(VectorCacheService::class);
    $vectorCache->shouldReceive('upsertNamespace')
        ->andReturnUsing(function ($id, $vector, $metadata, $namespace) use (&$capturedMetadata) {
            $capturedMetadata[] = $metadata;
            return true;
        });
    $this->instance(VectorCacheService::class, $vectorCache);

    Artisan::call('sentinel:ingest', ['--path' => 'policies']);

    $domains = array_unique(array_column($capturedMetadata, 'domain'));
    expect($domains)->not->toBeEmpty()
        ->and($domains)->toContain('aml')
        ->and($domains)->toContain('gdpr');
});
