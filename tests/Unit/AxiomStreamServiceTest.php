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

// ─── autoClaim() ─────────────────────────────────────────────────────────────

it('issues XAUTOCLAIM with the correct parameters', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) =>
            $args[0] === 'XAUTOCLAIM'
            && $args[1] === 'synapse:axioms'
            && $args[2] === 'axiom-workers'
            && $args[3] === 'axiom-worker-1'
            && $args[4] === '30000'
        ))
        ->andReturn(['0-0', [], []]);

    $result = (new AxiomStreamService())->autoClaim('axiom-worker-1', 30_000);

    expect($result)->toBeEmpty();
});

it('returns claimed messages from XAUTOCLAIM response', function () {
    $message = ['4-0', ['status', 'warning', 'anomaly_score', '0.85']];

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturn(['0-0', [$message], []]);

    $result = (new AxiomStreamService())->autoClaim('axiom-worker-1', 30_000);

    expect($result)->toHaveCount(1)
        ->and($result[0][0])->toBe('4-0');
});

// ─── deliveryCount() ─────────────────────────────────────────────────────────

it('returns the delivery count parsed from XPENDING', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) =>
            $args[0] === 'XPENDING'
            && $args[1] === 'synapse:axioms'
            && $args[2] === 'axiom-workers'
            && in_array('IDLE', $args, true)
        ))
        ->andReturn([['6-0', 'axiom-worker-1', 5000, 3]]);

    expect((new AxiomStreamService())->deliveryCount('6-0'))->toBe(3);
});

it('returns zero when the message is not in the PEL', function () {
    LRedis::shouldReceive('executeRaw')->once()->andReturn([]);

    expect((new AxiomStreamService())->deliveryCount('99-0'))->toBe(0);
});

// ─── parseFields() ───────────────────────────────────────────────────────────

it('parses a flat field-value list into an associative array', function () {
    $flat   = ['status', 'critical', 'source_id', 'sensor-42', 'anomaly_score', '0.91', 'metric_value', '94.0'];
    $result = (new AxiomStreamService())->parseFields($flat);

    expect($result['fields']['status'])->toBe('critical')
        ->and($result['fields']['source_id'])->toBe('sensor-42')
        ->and($result['fields']['anomaly_score'])->toBe(0.91)
        ->and($result['fields']['metric_value'])->toBe(94.0)
        ->and($result['traceparent'])->toBeNull();
});

it('casts anomaly_score and metric_value to float', function () {
    $flat   = ['anomaly_score', '0.75', 'metric_value', '50'];
    $result = (new AxiomStreamService())->parseFields($flat);

    expect($result['fields']['anomaly_score'])->toBeFloat()->toBe(0.75)
        ->and($result['fields']['metric_value'])->toBeFloat()->toBe(50.0);
});

it('surfaces traceparent separately from axiom fields', function () {
    $flat   = ['source_id', 'sensor-1', 'traceparent', '00-aabbcc-11223344-01'];
    $result = (new AxiomStreamService())->parseFields($flat);

    expect($result['traceparent'])->toBe('00-aabbcc-11223344-01')
        ->and(array_key_exists('traceparent', $result['fields']))->toBeFalse();
});
