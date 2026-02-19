<?php
use App\Services\TransactionStreamService;
use Illuminate\Support\Facades\Redis as LRedis;

uses(Tests\TestCase::class);

it('generates transactions with the required fields', function () {
    $service = new TransactionStreamService();
    $generator = $service->generate();

    $transaction = $generator->current();

    expect($transaction)->toHaveKeys(['id', 'merchant', 'currency', 'amount', 'timestamp']);
});

it('generates unique ids on every yield', function () {
    $service = new TransactionStreamService();
    $generator = $service->generate();

    $t1 = $generator->current();
    $generator->next();
    $t2 = $generator->current();

    expect($t1['id'])->not->toBe($t2['id']);
});

it('only yields merchants and currencies from config', function () {
    $service = new TransactionStreamService();
    $generator = $service->generate();

    $transaction = $generator->current();

    expect($transaction['merchant'])->toBeIn(config('sentinel.simulation.merchants'));
    expect($transaction['currency'])->toBeIn(config('sentinel.simulation.currencies'));
});

it('generates amounts within the expected range', function () {
    $service = new TransactionStreamService();
    $generator = $service->generate();

    foreach (range(1, 20) as $i) {
        $amount = $generator->current()['amount'];
        expect($amount)->toBeGreaterThanOrEqual(1.00)->toBeLessThanOrEqual(500.00);
        $generator->next();
    }
});

it('publishes a new transaction and returns true', function () {
    LRedis::shouldReceive('set')
        ->once()
        ->with('idemp:abc-123', 'processed', 'EX', 86400, 'NX')
        ->andReturn(true);

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(function ($args) {
            return $args[0] === 'XADD'
                && $args[1] === 'transactions';
        }))
        ->andReturn('1-0');

    $service = new TransactionStreamService();
    $result = $service->publish(['id' => 'abc-123', 'merchant' => 'Costco', 'amount' => 9.99]);

    expect($result)->toBeTrue();
});

it('rejects a duplicate transaction and returns false', function () {
    LRedis::shouldReceive('set')
        ->once()
        ->with('idemp:abc-123', 'processed', 'EX', 86400, 'NX')
        ->andReturn(false);

    LRedis::shouldNotReceive('executeRaw');

    $service = new TransactionStreamService();
    $result = $service->publish(['id' => 'abc-123', 'merchant' => 'Costco', 'amount' => 9.99]);

    expect($result)->toBeFalse();
});

it('returns an empty array when the stream has no messages', function () {
    LRedis::shouldReceive('executeRaw')->andReturn(null);

    $service = new TransactionStreamService();

    expect($service->read())->toBe([]);
});

it('returns decoded messages from the stream', function () {
    $fakeMessages = [['1-0', ['data', '{"merchant":"Costco","amount":9.99}']]];

    LRedis::shouldReceive('executeRaw')
        ->andReturn([['transactions', $fakeMessages]]);

    $service = new TransactionStreamService();

    expect($service->read())->toBe($fakeMessages);
});
