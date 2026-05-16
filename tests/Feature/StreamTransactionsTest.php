<?php
use App\Services\TransactionStreamService;
use Illuminate\Support\Facades\Redis as LRedis;

uses(Tests\TestCase::class);

it('streams a transaction to the redis stream', function () {
    LRedis::shouldReceive('set')->once()->andReturn(true);

    LRedis::shouldReceive('executeRaw')
        ->with(['XLEN', 'transactions'])
        ->andReturn(0);

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn($args) => $args[0] === 'XADD' && $args[1] === 'transactions'))
        ->andReturn('12345-0');

    $this->artisan('sentinel:stream --limit=1')
        ->assertExitCode(0);
});

it('stops after the given limit and prints the shutdown message', function () {
    LRedis::shouldReceive('set')->once()->andReturn(true);

    LRedis::shouldReceive('executeRaw')
        ->with(['XLEN', 'transactions'])
        ->andReturn(0);

    LRedis::shouldReceive('executeRaw')
        ->once()
        ->with(Mockery::on(fn($args) => $args[0] === 'XADD'));

    $this->artisan('sentinel:stream --limit=1')
        ->assertExitCode(0)
        ->expectsOutput('Limit reached. Powering down.');
});

it('pauses the publisher while stream depth exceeds the configured threshold', function () {
    config()->set('sentinel.backpressure.publish_pause_threshold', 800);
    config()->set('sentinel.backpressure.publish_pause_ms', 1);

    $fakeTransaction = [
        'id'        => 'txn-pause-test',
        'merchant'  => 'Costco',
        'amount'    => 1.00,
        'currency'  => 'CAD',
        'timestamp' => now()->toIso8601String(),
    ];

    $depthCalls = 0;
    $stream = Mockery::mock(TransactionStreamService::class);
    $stream->shouldReceive('generate')->andReturnUsing(function () use ($fakeTransaction) {
        yield $fakeTransaction;
    });
    $stream->shouldReceive('depth')->andReturnUsing(function () use (&$depthCalls) {
        $depthCalls++;
        // First two probes report a deep stream; third reports drained so publish proceeds.
        return $depthCalls < 3 ? 1000 : 0;
    });
    $stream->shouldReceive('publish')->once()->andReturn(true);

    $this->app->instance(TransactionStreamService::class, $stream);

    $this->artisan('sentinel:stream --limit=1 --speed=0')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stream depth above 800');

    expect($depthCalls)->toBeGreaterThanOrEqual(3);

    Mockery::close();
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
    $stream->shouldReceive('depth')->andReturn(0);
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
    $stream->shouldReceive('depth')->andReturn(0);
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
