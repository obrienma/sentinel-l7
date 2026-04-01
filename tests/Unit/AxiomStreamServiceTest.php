<?php

use App\Services\AxiomStreamService;
use Illuminate\Support\Facades\Redis as LRedis;

uses(Tests\TestCase::class);

$axiom = [
    'status'        => 'critical',
    'metric_value'  => 94.0,
    'anomaly_score' => 0.91,
    'source_id'     => 'sensor-42',
    'emitted_at'    => '2026-03-31T14:22:11Z',
];

// ─── publish() ────────────────────────────────────────────────────────────────

it('publishes an axiom using XADD on synapse:axioms', function () use ($axiom) {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) => $args[0] === 'XADD' && $args[1] === 'synapse:axioms'));

    $result = (new AxiomStreamService())->publish($axiom);

    expect($result)->toBeTrue();
});

it('json-encodes the payload into the data field', function () use ($axiom) {
    $captured = null;

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturnUsing(function ($args) use (&$captured) {
            $captured = $args;
            return '1-0';
        });

    (new AxiomStreamService())->publish($axiom);

    // XADD key MAXLEN ~ N * field value — field is at index 6, value at 7
    expect($captured[6])->toBe('data')
        ->and(json_decode($captured[7], true))->toMatchArray($axiom);
});

// ─── read() ───────────────────────────────────────────────────────────────────

it('returns messages and cursor from the stream', function () {
    $message = ['1-0', ['data', json_encode(['source_id' => 'sensor-1'])]];

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturn([['synapse:axioms', [$message]]]);

    $result = (new AxiomStreamService())->read('$');

    expect($result['messages'])->toHaveCount(1)
        ->and($result['cursor'])->toBe('1-0');
});

it('returns empty messages and original cursor when Redis returns null', function () {
    LRedis::shouldReceive('executeRaw')->once()->andReturn(null);

    $result = (new AxiomStreamService())->read('$');

    expect($result['messages'])->toBeEmpty()
        ->and($result['cursor'])->toBe('$');
});

it('reads from the synapse:axioms stream key', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) => in_array('synapse:axioms', $args)))
        ->andReturn(null);

    (new AxiomStreamService())->read('$');
});
