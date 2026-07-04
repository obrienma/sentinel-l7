<?php

use App\Services\AxiomThreatAnalysisService;

uses(Tests\TestCase::class);

$base = [
    'status' => 'critical',
    'metric_value' => 94.0,
    'anomaly_score' => 0.91,
    'source_id' => 'sensor-42',
    'emitted_at' => '2026-03-31T14:22:11Z',
];

it('returns a high risk_level with a rule-based narrative', function () use ($base) {
    $result = (new AxiomThreatAnalysisService)->analyze($base);

    expect($result['risk_level'])->toBe('high')
        ->and($result['narrative'])->toContain('Rule-based fallback')
        ->and($result['narrative'])->toContain('0.91');
});

it('includes the domain in the narrative when present', function () use ($base) {
    $result = (new AxiomThreatAnalysisService)->analyze([...$base, 'domain' => 'aml']);

    expect($result['narrative'])->toContain('aml');
});

it('falls back to "unspecified" in the narrative when domain is absent', function () use ($base) {
    $result = (new AxiomThreatAnalysisService)->analyze($base);

    expect($result['narrative'])->toContain('unspecified');
});

it('reflects a custom axiom_threshold in the narrative', function () use ($base) {
    config(['sentinel.axiom_threshold' => 0.5]);

    $result = (new AxiomThreatAnalysisService)->analyze($base);

    expect($result['narrative'])->toContain('0.5');
});
