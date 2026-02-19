<?php

use App\Services\ThreatAnalysisService;
use App\Services\ThreatResult;

uses(Tests\TestCase::class);

$base = [
    'id'        => 'abc-123',
    'merchant'  => 'Costco',
    'currency'  => 'CAD',
    'timestamp' => '2026-01-01T00:00:00+00:00',
];

it('flags a transaction above the threshold as a threat', function () use ($base) {
    $result = (new ThreatAnalysisService())->analyze([...$base, 'amount' => 401.00]);

    expect($result)->toBeInstanceOf(ThreatResult::class)
        ->isThreat->toBeTrue()
        ->message->toContain('Costco')
        ->message->toContain('401.00');
});

it('clears a transaction at or below the threshold', function () use ($base) {
    $result = (new ThreatAnalysisService())->analyze([...$base, 'amount' => 400.00]);

    expect($result)->toBeInstanceOf(ThreatResult::class)
        ->isThreat->toBeFalse()
        ->message->toContain('Layer 7 Clear')
        ->message->toContain('Costco');
});

it('clears a transaction well below the threshold', function () use ($base) {
    $result = (new ThreatAnalysisService())->analyze([...$base, 'amount' => 10.00]);

    expect($result->isThreat)->toBeFalse();
});

it('includes the original transaction on the result', function () use ($base) {
    $transaction = [...$base, 'amount' => 999.00];
    $result = (new ThreatAnalysisService())->analyze($transaction);

    expect($result->transaction)->toBe($transaction);
});

it('respects a custom threshold from config', function () use ($base) {
    config(['sentinel.thresholds.high_risk' => 100.00]);

    $result = (new ThreatAnalysisService())->analyze([...$base, 'amount' => 101.00]);

    expect($result->isThreat)->toBeTrue();
});
