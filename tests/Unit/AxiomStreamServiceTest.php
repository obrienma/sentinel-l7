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

// ─── ensureConsumerGroup() ────────────────────────────────────────────────────

it('issues XGROUP CREATE for the axiom-workers group', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) => $args[0] === 'XGROUP' && $args[3] === 'axiom-workers'));

    (new AxiomStreamService())->ensureConsumerGroup();
});

it('ignores BUSYGROUP error when the consumer group already exists', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andThrow(new \Predis\Response\ServerException('BUSYGROUP Consumer Group name already exists'));

    expect(fn () => (new AxiomStreamService())->ensureConsumerGroup())->not->toThrow(\Exception::class);
});

it('re-throws non-BUSYGROUP Redis errors', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andThrow(new \Predis\Response\ServerException('ERR some other error'));

    expect(fn () => (new AxiomStreamService())->ensureConsumerGroup())
        ->toThrow(\Predis\Response\ServerException::class);
});

// ─── readGroup() ─────────────────────────────────────────────────────────────

it('issues XREADGROUP for the axiom-workers group', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) => $args[0] === 'XREADGROUP' && in_array('axiom-workers', $args)))
        ->andReturn(null);

    $result = (new AxiomStreamService())->readGroup('worker-1');

    expect($result['messages'])->toBeEmpty();
});

it('returns messages from readGroup response', function () {
    $message = ['2-0', ['status', 'critical', 'anomaly_score', '0.91']];

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturn([['synapse:axioms', [$message]]]);

    $result = (new AxiomStreamService())->readGroup('worker-1');

    expect($result['messages'])->toHaveCount(1);
});

// ─── ack() ────────────────────────────────────────────────────────────────────

it('issues XACK with the correct stream key and group', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(['XACK', 'synapse:axioms', 'axiom-workers', '3-0']);

    (new AxiomStreamService())->ack('3-0');
});

// ─── claimPending() ──────────────────────────────────────────────────────────

it('issues XAUTOCLAIM with the correct parameters', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) => $args[0] === 'XAUTOCLAIM' && $args[1] === 'synapse:axioms'))
        ->andReturn(['0-0', [], []]);

    $result = (new AxiomStreamService())->claimPending('axiom-reclaimer');

    expect($result)->toBeEmpty();
});

it('returns claimed messages from XAUTOCLAIM response', function () {
    $message = ['4-0', ['status', 'warning', 'anomaly_score', '0.85']];

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturn(['0-0', [$message], []]);

    $result = (new AxiomStreamService())->claimPending('axiom-reclaimer');

    expect($result)->toHaveCount(1)
        ->and($result[0][0])->toBe('4-0');
});

// ─── parseFields() ───────────────────────────────────────────────────────────

it('parses a flat field-value list into an associative array', function () {
    $flat   = ['status', 'critical', 'source_id', 'sensor-42', 'anomaly_score', '0.91', 'metric_value', '94.0'];
    $result = (new AxiomStreamService())->parseFields($flat);

    expect($result['status'])->toBe('critical')
        ->and($result['source_id'])->toBe('sensor-42')
        ->and($result['anomaly_score'])->toBe(0.91)
        ->and($result['metric_value'])->toBe(94.0);
});

it('casts anomaly_score and metric_value to float', function () {
    $flat   = ['anomaly_score', '0.75', 'metric_value', '50'];
    $result = (new AxiomStreamService())->parseFields($flat);

    expect($result['anomaly_score'])->toBeFloat()->toBe(0.75)
        ->and($result['metric_value'])->toBeFloat()->toBe(50.0);
});
