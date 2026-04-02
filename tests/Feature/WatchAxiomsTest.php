<?php

use App\Services\AxiomProcessorService;
use App\Services\AxiomStreamService;

uses(Tests\TestCase::class);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fakeAxiomMessage(array $overrides = []): array
{
    $data = array_merge([
        'status'        => 'critical',
        'metric_value'  => 94.0,
        'anomaly_score' => 0.91,
        'source_id'     => 'sensor-42',
        'emitted_at'    => '2026-03-31T14:22:11Z',
    ], $overrides);

    // Match Python sidecar format: flat [field, value, field, value, ...]
    $flat = [];
    foreach ($data as $key => $value) {
        $flat[] = $key;
        $flat[] = (string) ($value ?? '');
    }

    return ['1-0', $flat];
}

/**
 * Build a parsed associative array from fakeAxiomMessage's flat payload,
 * mirroring what AxiomStreamService::parseFields() would return.
 */
function parseFakeAxiomFlat(array $flat): array
{
    $data = [];
    for ($i = 0; $i < count($flat); $i += 2) {
        $data[$flat[$i]] = $flat[$i + 1];
    }
    if (isset($data['anomaly_score'])) {
        $data['anomaly_score'] = (float) $data['anomaly_score'];
    }
    if (isset($data['metric_value'])) {
        $data['metric_value'] = (float) $data['metric_value'];
    }

    return $data;
}

function mockAxiomStreamWithOneMessage(array $overrides = []): \Mockery\MockInterface
{
    $calls = 0;
    $msg   = fakeAxiomMessage($overrides);

    $mock = Mockery::mock(AxiomStreamService::class);
    $mock->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $mock->shouldReceive('readGroup')
        ->andReturnUsing(function () use (&$calls, $msg) {
            $calls++;
            if ($calls === 1) {
                return ['messages' => [$msg]];
            }
            throw new \RuntimeException('__test_stop__');
        });
    $mock->shouldReceive('parseFields')
        ->andReturnUsing(fn (array $flat) => parseFakeAxiomFlat($flat));
    $mock->shouldReceive('ack')->andReturnNull();

    return $mock;
}

function runAxiomWatcher(\Tests\TestCase $test): void
{
    try {
        $test->artisan('sentinel:watch-axioms');
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== '__test_stop__') {
            throw $e;
        }
    }
}

// ─── Processor calls ─────────────────────────────────────────────────────────

it('calls the processor once for each axiom message', function () {
    $callCount = 0;

    $processor = Mockery::mock(AxiomProcessorService::class);
    $processor->shouldReceive('process')
        ->andReturnUsing(function () use (&$callCount) {
            $callCount++;

            return ['source_id' => 'sensor-42', 'routed_to_ai' => true, 'risk_level' => 'high', 'narrative' => 'Audit.', 'elapsed_ms' => 1.0];
        });

    $this->app->instance(AxiomStreamService::class, mockAxiomStreamWithOneMessage());
    $this->app->instance(AxiomProcessorService::class, $processor);

    runAxiomWatcher($this);

    expect($callCount)->toBe(1);
});

it('passes the decoded axiom payload to the processor', function () {
    $received = null;

    $processor = Mockery::mock(AxiomProcessorService::class);
    $processor->shouldReceive('process')
        ->once()
        ->andReturnUsing(function ($data) use (&$received) {
            $received = $data;

            return ['source_id' => 'sensor-42', 'routed_to_ai' => false, 'risk_level' => 'low', 'narrative' => null, 'elapsed_ms' => 1.0];
        });

    $this->app->instance(AxiomStreamService::class, mockAxiomStreamWithOneMessage());
    $this->app->instance(AxiomProcessorService::class, $processor);

    runAxiomWatcher($this);

    expect($received['source_id'])->toBe('sensor-42')
        ->and($received['anomaly_score'])->toBe(0.91);

    Mockery::close();
});

it('processes two axioms from the same read batch', function () {
    $readCalls    = 0;
    $processCalls = 0;

    $stream = Mockery::mock(AxiomStreamService::class);
    $stream->shouldReceive('ensureConsumerGroup')->andReturnNull();
    $stream->shouldReceive('readGroup')->andReturnUsing(function () use (&$readCalls) {
        $readCalls++;
        if ($readCalls === 1) {
            return [
                'messages' => [
                    fakeAxiomMessage(['source_id' => 'sensor-1']),
                    fakeAxiomMessage(['source_id' => 'sensor-2']),
                ],
            ];
        }
        throw new \RuntimeException('__test_stop__');
    });
    $stream->shouldReceive('parseFields')
        ->andReturnUsing(fn (array $flat) => parseFakeAxiomFlat($flat));
    $stream->shouldReceive('ack')->andReturnNull();

    $processor = Mockery::mock(AxiomProcessorService::class);
    $processor->shouldReceive('process')
        ->andReturnUsing(function () use (&$processCalls) {
            $processCalls++;

            return ['source_id' => '?', 'routed_to_ai' => false, 'risk_level' => 'low', 'narrative' => null, 'elapsed_ms' => 1.0];
        });

    $this->app->instance(AxiomStreamService::class, $stream);
    $this->app->instance(AxiomProcessorService::class, $processor);

    runAxiomWatcher($this);

    expect($processCalls)->toBe(2);
});

// ─── Edge cases ───────────────────────────────────────────────────────────────

it('handles a missing source_id without throwing', function () {
    $processor = Mockery::mock(AxiomProcessorService::class);
    $processor->shouldReceive('process')->andReturn([
        'source_id' => 'unknown', 'routed_to_ai' => false,
        'risk_level' => 'low', 'narrative' => null, 'elapsed_ms' => 1.0,
    ]);

    $this->app->instance(AxiomStreamService::class, mockAxiomStreamWithOneMessage(['source_id' => null]));
    $this->app->instance(AxiomProcessorService::class, $processor);

    expect(fn () => runAxiomWatcher($this))->not->toThrow(\Exception::class);
});

it('handles a null narrative without throwing', function () {
    $processor = Mockery::mock(AxiomProcessorService::class);
    $processor->shouldReceive('process')->andReturn([
        'source_id' => 'sensor-42', 'routed_to_ai' => true,
        'risk_level' => 'unknown', 'narrative' => null, 'elapsed_ms' => 5.0,
    ]);

    $this->app->instance(AxiomStreamService::class, mockAxiomStreamWithOneMessage());
    $this->app->instance(AxiomProcessorService::class, $processor);

    expect(fn () => runAxiomWatcher($this))->not->toThrow(\Exception::class);
});
