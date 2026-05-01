<?php
use App\Services\TransactionStreamService;
use Illuminate\Support\Facades\Redis as LRedis;

uses(Tests\TestCase::class);

it('streams a transaction to the redis stream', function () {
    LRedis::shouldReceive('set')->once()->andReturn(true);

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn($args) => $args[0] === 'XADD' && $args[1] === 'transactions'))
        ->andReturn('12345-0');

    $this->artisan('sentinel:stream --limit=1')
        ->assertExitCode(0);
});

it('stops after the given limit and prints the shutdown message', function () {
    LRedis::shouldReceive('set')->once()->andReturn(true);
    LRedis::shouldReceive('executeRaw')->once();

    $this->artisan('sentinel:stream --limit=1')
        ->assertExitCode(0)
        ->expectsOutput('Limit reached. Powering down.');
});

it('stops cleanly on SIGINT and prints the signal shutdown message', function () {
    if (!extension_loaded('pcntl') || !function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl/posix extensions not available');
    }

    $publishCount = 0;

    $fakeTransaction = [
        'id' => 'txn-signal-test',
        'merchant' => 'Starbucks',
        'merchant_name' => 'Starbucks',
        'amount' => 9.50,
        'currency' => 'USD',
        'timestamp' => now()->toIso8601String(),
    ];

    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('generate')->andReturnUsing(function () use ($fakeTransaction) {
        yield $fakeTransaction;
        posix_kill(posix_getpid(), SIGINT); // fires handler before next loop check
        yield $fakeTransaction; // should never be published
    });
    $stream->shouldReceive('publish')->andReturnUsing(function () use (&$publishCount) {
        $publishCount++;
        return true;
    });

    $this->app->instance(TransactionStreamService::class, $stream);

    $this->artisan('sentinel:stream --limit=0 --speed=0')
        ->assertExitCode(0)
        ->expectsOutput('Signal received. Powering down.');

    expect($publishCount)->toBe(1);

    Mockery::close();
});

it('stops cleanly on SIGTERM', function () {
    if (!extension_loaded('pcntl') || !function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl/posix extensions not available');
    }

    $publishCount = 0;

    $fakeTransaction = [
        'id' => 'txn-sigterm-test',
        'merchant' => 'Costa',
        'merchant_name' => 'Costa',
        'amount' => 4.50,
        'currency' => 'GBP',
        'timestamp' => now()->toIso8601String(),
    ];

    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('generate')->andReturnUsing(function () use ($fakeTransaction) {
        yield $fakeTransaction;
        posix_kill(posix_getpid(), SIGTERM);
        yield $fakeTransaction;
    });
    $stream->shouldReceive('publish')->andReturnUsing(function () use (&$publishCount) {
        $publishCount++;
        return true;
    });

    $this->app->instance(TransactionStreamService::class, $stream);

    $this->artisan('sentinel:stream --limit=0 --speed=0')
        ->assertExitCode(0)
        ->expectsOutput('Signal received. Powering down.');

    expect($publishCount)->toBe(1);

    Mockery::close();
});
