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

it('returns the current stream depth via XLEN', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(['XLEN', 'transactions'])
        ->andReturn(42);

    expect((new TransactionStreamService())->depth())->toBe(42);
});

// ─── ensureConsumerGroup() ────────────────────────────────────────────────────

it('issues XGROUP CREATE with MKSTREAM for the sentinel-consumers group', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) =>
            $args[0] === 'XGROUP'
            && $args[1] === 'CREATE'
            && $args[2] === 'transactions'
            && $args[3] === 'sentinel-consumers'
            && in_array('MKSTREAM', $args, true)
        ));

    (new TransactionStreamService())->ensureConsumerGroup();
});

it('ignores BUSYGROUP error when the consumer group already exists', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andThrow(new \Predis\Response\ServerException('BUSYGROUP Consumer Group name already exists'));

    expect(fn () => (new TransactionStreamService())->ensureConsumerGroup())->not->toThrow(\Exception::class);
});

it('re-throws non-BUSYGROUP Redis errors from ensureConsumerGroup', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andThrow(new \Predis\Response\ServerException('ERR boom'));

    expect(fn () => (new TransactionStreamService())->ensureConsumerGroup())
        ->toThrow(\Predis\Response\ServerException::class);
});

// ─── readGroup() ─────────────────────────────────────────────────────────────

it('issues XREADGROUP with COUNT 1 so a deep stream cannot flood the loop', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(function ($args) {
            return $args[0] === 'XREADGROUP'
                && in_array('sentinel-consumers', $args, true)
                && in_array('worker-1', $args, true)
                && in_array('COUNT', $args, true)
                && $args[array_search('COUNT', $args, true) + 1] === '1';
        }))
        ->andReturn(null);

    $result = (new TransactionStreamService())->readGroup('worker-1');

    expect($result['messages'])->toBeEmpty();
});

it('returns messages from the readGroup response', function () {
    $message = ['1-0', ['data', '{"id":"txn-1"}']];

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturn([['transactions', [$message]]]);

    $result = (new TransactionStreamService())->readGroup('worker-1');

    expect($result['messages'])->toHaveCount(1)
        ->and($result['messages'][0][0])->toBe('1-0');
});

// ─── ack() ────────────────────────────────────────────────────────────────────

it('issues XACK with the correct stream key and group', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(['XACK', 'transactions', 'sentinel-consumers', '5-0']);

    (new TransactionStreamService())->ack('5-0');
});

// ─── autoClaim() ─────────────────────────────────────────────────────────────

it('issues XAUTOCLAIM with the configured min-idle and consumer', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) =>
            $args[0] === 'XAUTOCLAIM'
            && $args[1] === 'transactions'
            && $args[2] === 'sentinel-consumers'
            && $args[3] === 'worker-1'
            && $args[4] === '30000'
        ))
        ->andReturn(['0-0', [], []]);

    $result = (new TransactionStreamService())->autoClaim('worker-1', 30_000);

    expect($result)->toBeEmpty();
});

it('returns claimed messages from the XAUTOCLAIM response', function () {
    $message = ['4-0', ['data', '{"id":"txn-x"}']];

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->andReturn(['0-0', [$message], []]);

    $result = (new TransactionStreamService())->autoClaim('worker-1', 30_000);

    expect($result)->toHaveCount(1)
        ->and($result[0][0])->toBe('4-0');
});

// ─── deliveryCount() ─────────────────────────────────────────────────────────

it('returns the delivery count parsed from the XPENDING response', function () {
    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn ($args) =>
            $args[0] === 'XPENDING'
            && $args[1] === 'transactions'
            && $args[2] === 'sentinel-consumers'
            && in_array('IDLE', $args, true)
        ))
        ->andReturn([['5-0', 'worker-1', 1234, 4]]);

    expect((new TransactionStreamService())->deliveryCount('5-0'))->toBe(4);
});

it('returns zero when the message is not in the PEL', function () {
    LRedis::shouldReceive('executeRaw')->once()->andReturn([]);

    expect((new TransactionStreamService())->deliveryCount('99-0'))->toBe(0);
});
