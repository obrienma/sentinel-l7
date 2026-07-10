<?php

use App\Models\ComplianceEvent;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['services.ledger_l5.api_key' => 'test-secret-key']);
    config(['sentinel.usage.page_size' => 500]);
    config(['sentinel.usage.safety_lag_seconds' => 60]);
});

// created_at isn't fillable on either model (Eloquent-managed), so
// create() always stamps "now" regardless of $overrides — set it via
// direct property assignment + save() afterward instead. Defaults to
// 120s ago (past the default 60s safety-lag window) since most tests
// want rows to be visible; pass secondsAgo: 0 to test the lag itself.
function usageTxn(array $overrides = [], int $secondsAgo = 120): Transaction
{
    $txn = Transaction::create(array_merge([
        'txn_id' => (string) Str::uuid(),
        'merchant' => 'ACME Corp',
        'amount' => 150.00,
        'currency' => 'AUD',
        'is_threat' => false,
        'message' => 'Layer 7 Clear',
        'source' => 'cache_miss',
    ], $overrides));

    $txn->created_at = now()->subSeconds($secondsAgo);
    $txn->save();

    return $txn;
}

function usageEvent(array $overrides = [], int $secondsAgo = 120): ComplianceEvent
{
    $event = ComplianceEvent::create(array_merge([
        'source_id' => (string) Str::uuid(),
        'domain' => 'aml',
        'status' => 'warn',
        'metric_value' => 812.4,
        'anomaly_score' => 0.91,
        'routed_to_ai' => true,
        'audit_narrative' => 'Structuring pattern detected.',
        'driver_used' => 'ollama',
    ], $overrides));

    $event->created_at = now()->subSeconds($secondsAgo);
    $event->save();

    return $event;
}

// ─── Auth ───────────────────────────────────────────────────────────────────

it('rejects a request with no API key', function () {
    $this->getJson('/usage')->assertStatus(401);
});

it('rejects a request with the wrong API key', function () {
    $this->withHeaders(['X-Ledger-Api-Key' => 'wrong-key'])
        ->getJson('/usage')
        ->assertStatus(401);
});

it('accepts a request with the correct API key', function () {
    $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
        ->getJson('/usage')
        ->assertStatus(200);
});

it('never echoes the presented key back in the response', function () {
    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'wrong-key'])->getJson('/usage');

    expect($response->getContent())->not->toContain('wrong-key');
});

it('rejects an insecure request in production', function () {
    app()->instance('env', 'production');

    try {
        $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
            ->getJson('/usage')
            ->assertStatus(400);
    } finally {
        app()->instance('env', 'testing');
    }
});

it('allows a secure request in production', function () {
    app()->instance('env', 'production');

    try {
        // Passing an absolute https:// URL (rather than a relative path +
        // withServerVariables(['HTTPS' => 'on'])) is what actually makes
        // isSecure() true here — Symfony's Request::create() derives the
        // HTTPS server var from the URL's own scheme, which otherwise wins
        // over a manually-set server variable for a relative http:// URI.
        $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
            ->getJson('https://localhost/usage')
            ->assertStatus(200);
    } finally {
        app()->instance('env', 'testing');
    }
});

// ─── Response shape ─────────────────────────────────────────────────────────

it('returns the ADR-0029 response shape', function () {
    usageTxn();
    usageEvent();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])->getJson('/usage');

    $response->assertOk()->assertJsonStructure([
        'transactions' => [['id', 'txn_id', 'merchant', 'amount', 'currency', 'is_threat', 'message', 'source', 'created_at']],
        'compliance_events' => [['id', 'source_id', 'domain', 'status', 'metric_value', 'anomaly_score', 'emitted_at', 'routed_to_ai', 'audit_narrative', 'driver_used', 'created_at']],
        'next_cursor' => ['since_transactions', 'since_compliance_events'],
    ]);
});

it('does not include updated_at on either row shape', function () {
    usageTxn();
    usageEvent();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])->getJson('/usage');

    expect($response->json('transactions.0'))->not->toHaveKey('updated_at')
        ->and($response->json('compliance_events.0'))->not->toHaveKey('updated_at');
});

// ─── Cursor filtering ───────────────────────────────────────────────────────

it('only returns transactions with id greater than since_transactions', function () {
    $first = usageTxn();
    $second = usageTxn();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
        ->getJson('/usage?since_transactions='.$first->id);

    expect($response->json('transactions'))->toHaveCount(1)
        ->and($response->json('transactions.0.id'))->toBe($second->id);
});

it('only returns compliance_events with id greater than since_compliance_events', function () {
    $first = usageEvent();
    $second = usageEvent();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
        ->getJson('/usage?since_compliance_events='.$first->id);

    expect($response->json('compliance_events'))->toHaveCount(1)
        ->and($response->json('compliance_events.0.id'))->toBe($second->id);
});

it('tracks each pipeline cursor independently', function () {
    $txn = usageTxn();
    usageEvent();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
        ->getJson('/usage?since_transactions='.$txn->id);

    expect($response->json('transactions'))->toHaveCount(0)
        ->and($response->json('compliance_events'))->toHaveCount(1);
});

// ─── Safety-lag window ──────────────────────────────────────────────────────

it('excludes rows created within the safety-lag window', function () {
    usageTxn(secondsAgo: 0);

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])->getJson('/usage');

    expect($response->json('transactions'))->toHaveCount(0);
});

it('includes rows created before the safety-lag window', function () {
    usageTxn();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])->getJson('/usage');

    expect($response->json('transactions'))->toHaveCount(1);
});

// ─── Page size ───────────────────────────────────────────────────────────────

it('caps the number of transactions returned at the configured page size', function () {
    config(['sentinel.usage.page_size' => 2]);

    usageTxn();
    usageTxn();
    usageTxn();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])->getJson('/usage');

    expect($response->json('transactions'))->toHaveCount(2);
});

// ─── next_cursor ────────────────────────────────────────────────────────────

it('sets next_cursor to the max id returned per pipeline', function () {
    usageTxn();
    $second = usageTxn();

    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])->getJson('/usage');

    expect($response->json('next_cursor.since_transactions'))->toBe($second->id);
});

it('keeps next_cursor unchanged from the request when a pipeline has no new rows', function () {
    $response = $this->withHeaders(['X-Ledger-Api-Key' => 'test-secret-key'])
        ->getJson('/usage?since_transactions=42&since_compliance_events=7');

    expect($response->json('next_cursor.since_transactions'))->toBe(42)
        ->and($response->json('next_cursor.since_compliance_events'))->toBe(7);
});
