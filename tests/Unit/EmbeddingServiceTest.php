<?php

use App\Contracts\EmbeddingDriver;
use App\Services\EmbeddingService;

uses(Tests\TestCase::class);

function embeddingService(): EmbeddingService
{
    return new EmbeddingService(Mockery::mock(EmbeddingDriver::class));
}

// ─── createTransactionFingerprint ────────────────────────────────────────────

it('builds a fingerprint with all fields present', function () {
    $service = embeddingService();

    $fingerprint = $service->createTransactionFingerprint([
        'amount' => '12.50',
        'currency' => 'CAD',
        'type' => 'purchase',
        'category' => 'coffee',
        'timestamp' => '2026-01-01T09:14:00+00:00',
        'merchant_name' => 'Starbucks',
    ]);

    expect($fingerprint)
        ->toContain('Amount: small CAD')
        ->toContain('Type: purchase')
        ->toContain('Category: coffee')
        ->toContain('Merchant: Starbucks')
        ->toContain('Time: morning');
});

it('uses N/A for missing amount and currency', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)
        ->toContain('Amount: N/A N/A');
});

it('defaults category to "unknown" when absent', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)->toContain('Category: unknown');
});

it('uses N/A for merchant when absent', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)->toContain('Merchant: N/A');
});

it('uses N/A for time when timestamp is absent', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([]);

    expect($fingerprint)->toContain('Time: N/A');
});

it('pipe-delimits the fingerprint fields', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([
        'amount' => '5.00',
        'currency' => 'USD',
    ]);

    expect(substr_count($fingerprint, ' | '))->toBe(4);
});

it('produces identical fingerprints for identical inputs', function () {
    $service = embeddingService();
    $txn = ['amount' => '25.00', 'currency' => 'EUR', 'type' => 'purchase', 'category' => 'food', 'timestamp' => '2026-03-01T12:00:00+00:00', 'merchant_name' => 'Costco'];

    expect($service->createTransactionFingerprint($txn))
        ->toBe($service->createTransactionFingerprint($txn));
});

it('produces different fingerprints for different merchants', function () {
    $service = embeddingService();
    $base = ['amount' => '25.00', 'currency' => 'EUR', 'type' => 'purchase'];

    $fp1 = $service->createTransactionFingerprint([...$base, 'merchant_name' => 'Costco']);
    $fp2 = $service->createTransactionFingerprint([...$base, 'merchant_name' => 'Walmart']);

    expect($fp1)->not->toBe($fp2);
});

it('reads merchant key used by the stream generator', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([
        'amount' => '25.00',
        'currency' => 'CAD',
        'merchant' => 'Costco',
    ]);

    expect($fingerprint)->toContain('Merchant: Costco');
});

it('prefers merchant over merchant_name when both are present', function () {
    $service = embeddingService();
    $fingerprint = $service->createTransactionFingerprint([
        'amount' => '25.00',
        'currency' => 'CAD',
        'merchant' => 'Costco',
        'merchant_name' => 'ShouldBeIgnored',
    ]);

    expect($fingerprint)
        ->toContain('Merchant: Costco')
        ->not->toContain('ShouldBeIgnored');
});

// ─── embed (delegation) ────────────────────────────────────────────────────────

it('delegates embed() to the injected driver with the given task', function () {
    $driver = Mockery::mock(EmbeddingDriver::class);
    $driver->shouldReceive('embed')
        ->once()
        ->with('some text', EmbeddingDriver::TASK_QUERY)
        ->andReturn([0.1, 0.2]);

    $service = new EmbeddingService($driver);

    expect($service->embed('some text', EmbeddingDriver::TASK_QUERY))->toBe([0.1, 0.2]);
});

it('defaults embed() to TASK_DOCUMENT when no task is given', function () {
    $driver = Mockery::mock(EmbeddingDriver::class);
    $driver->shouldReceive('embed')
        ->once()
        ->with('some text', EmbeddingDriver::TASK_DOCUMENT)
        ->andReturn([0.1]);

    $service = new EmbeddingService($driver);
    $service->embed('some text');
});
