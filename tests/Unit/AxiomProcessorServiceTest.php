<?php

use App\Contracts\ComplianceDriver;
use App\Models\ComplianceEvent;
use App\Services\AxiomProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

$baseAxiom = [
    'status'        => 'critical',
    'metric_value'  => 94.0,
    'anomaly_score' => 0.91,
    'source_id'     => 'sensor-42',
    'emitted_at'    => '2026-03-31T14:22:11Z',
];

// ─── Routing ──────────────────────────────────────────────────────────────────

it('calls the driver when anomaly_score exceeds the threshold', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->once()->andReturn([
        'narrative'   => 'Audit complete.',
        'risk_level'  => 'high',
        'policy_refs' => [],
        'confidence'  => 0.9,
    ]);

    (new AxiomProcessorService($driver))->process($baseAxiom);

    expect(ComplianceEvent::first()->routed_to_ai)->toBeTrue();
    Mockery::close();
});

it('does not call the driver when anomaly_score is at the threshold', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.8]);

    expect(ComplianceEvent::first()->routed_to_ai)->toBeFalse();
    Mockery::close();
});

it('does not call the driver when anomaly_score is below the threshold', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.5]);

    expect(ComplianceEvent::first()->routed_to_ai)->toBeFalse();
    Mockery::close();
});

it('respects a custom threshold from config', function () use ($baseAxiom) {
    config(['sentinel.axiom_threshold' => 0.5]);

    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->once()->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'medium', 'policy_refs' => [], 'confidence' => 0.7,
    ]);

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.6]);

    expect(ComplianceEvent::first()->routed_to_ai)->toBeTrue();
    Mockery::close();
});

// ─── Persistence ──────────────────────────────────────────────────────────────

it('persists a ComplianceEvent for every axiom regardless of routing', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
    ]);

    (new AxiomProcessorService($driver))->process($baseAxiom);
    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'source_id' => 'sensor-43', 'anomaly_score' => 0.1]);

    expect(ComplianceEvent::count())->toBe(2);
});

it('stores source_id on the compliance event', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.1]);

    expect(ComplianceEvent::first()->source_id)->toBe('sensor-42');
});

it('stores routed_to_ai true and driver_used when ai-routed', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
    ]);

    (new AxiomProcessorService($driver))->process($baseAxiom);

    $event = ComplianceEvent::first();
    expect($event->routed_to_ai)->toBeTrue()
        ->and($event->driver_used)->toBe(config('sentinel.ai_driver'));
});

it('stores routed_to_ai false and null driver_used when sub-threshold', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.1]);

    $event = ComplianceEvent::first();
    expect($event->routed_to_ai)->toBeFalse()
        ->and($event->driver_used)->toBeNull()
        ->and($event->audit_narrative)->toBeNull();
});

it('stores the audit narrative from the driver', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andReturn([
        'narrative' => 'Metric exceeded safe threshold.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
    ]);

    (new AxiomProcessorService($driver))->process($baseAxiom);

    expect(ComplianceEvent::first()->audit_narrative)->toBe('Metric exceeded safe threshold.');
});

// ─── Resilience ───────────────────────────────────────────────────────────────

it('falls back to a rule-based verdict when the driver throws', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andThrow(new \RuntimeException('Flash API timeout'));

    $result = (new AxiomProcessorService($driver))->process($baseAxiom);

    $event = ComplianceEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->audit_narrative)->not->toBeNull()
        ->and($event->audit_narrative)->toContain('Rule-based fallback')
        ->and($event->driver_used)->toBe('fallback')
        ->and($event->routed_to_ai)->toBeTrue()
        ->and($result['risk_level'])->toBe('high');
});

it('logs an error when the driver throws', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andThrow(new \RuntimeException('Flash API timeout'));

    \Illuminate\Support\Facades\Log::shouldReceive('error')->once()
        ->with('AxiomProcessorService: AI analysis failed', Mockery::any());

    (new AxiomProcessorService($driver))->process($baseAxiom);
});

// ─── Idempotency ─────────────────────────────────────────────────────────────

it('does not create a duplicate event when the same source_id is re-delivered (sub-threshold)', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    $payload = [...$baseAxiom, 'anomaly_score' => 0.1];
    (new AxiomProcessorService($driver))->process($payload);
    (new AxiomProcessorService($driver))->process($payload);

    expect(ComplianceEvent::count())->toBe(1);
    Mockery::close();
});

it('does not create a duplicate event when the same source_id is re-delivered (ai-routed)', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->once()->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
    ]);

    (new AxiomProcessorService($driver))->process($baseAxiom);
    (new AxiomProcessorService($driver))->process($baseAxiom);

    expect(ComplianceEvent::count())->toBe(1);
    Mockery::close();
});

it('returns risk_level skipped and skips the driver on re-delivery', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->once()->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
    ]);

    (new AxiomProcessorService($driver))->process($baseAxiom);
    $result = (new AxiomProcessorService($driver))->process($baseAxiom);

    expect($result['risk_level'])->toBe('skipped')
        ->and($result['routed_to_ai'])->toBeFalse()
        ->and($result['narrative'])->toBeNull()
        ->and($result['source_id'])->toBe('sensor-42');
    Mockery::close();
});

// ─── Domain stamping ──────────────────────────────────────────────────────────

it('persists domain on the compliance event when present', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.1, 'domain' => 'aml']);

    expect(ComplianceEvent::first()->domain)->toBe('aml');
    Mockery::close();
});

it('persists null domain when absent', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.1]);

    expect(ComplianceEvent::first()->domain)->toBeNull();
    Mockery::close();
});

it('returns domain in the outcome array', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'high', 'policy_refs' => [], 'confidence' => 0.9,
    ]);

    $result = (new AxiomProcessorService($driver))->process([...$baseAxiom, 'domain' => 'aml']);

    expect($result['domain'])->toBe('aml');
    Mockery::close();
});

// ─── Return shape ─────────────────────────────────────────────────────────────

it('returns routed_to_ai true and risk_level for ai-routed axioms', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldReceive('analyze')->andReturn([
        'narrative' => 'Audit.', 'risk_level' => 'critical', 'policy_refs' => [], 'confidence' => 0.95,
    ]);

    $result = (new AxiomProcessorService($driver))->process($baseAxiom);

    expect($result['routed_to_ai'])->toBeTrue()
        ->and($result['risk_level'])->toBe('critical')
        ->and($result['source_id'])->toBe('sensor-42')
        ->and($result['elapsed_ms'])->toBeFloat();
});

it('returns routed_to_ai false and risk_level low for sub-threshold axioms', function () use ($baseAxiom) {
    $driver = Mockery::mock(ComplianceDriver::class);
    $driver->shouldNotReceive('analyze');

    $result = (new AxiomProcessorService($driver))->process([...$baseAxiom, 'anomaly_score' => 0.1]);

    expect($result['routed_to_ai'])->toBeFalse()
        ->and($result['risk_level'])->toBe('low')
        ->and($result['narrative'])->toBeNull()
        ->and($result['elapsed_ms'])->toBeFloat();
});
