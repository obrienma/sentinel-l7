<?php

use App\Console\Commands\StreamTransactions;
use Illuminate\Support\Facades\Redis as LRedis;

uses(Tests\TestCase::class);

it('streams transactions to upstash using the sentinel generator', function () {
    // 1. Manually mock the executeRaw call
    LRedis::shouldReceive('executeRaw')
        ->once() // We expect it to be called once because of the 'testing' break
        ->with(Mockery::on(function ($args) {
            // Check that it's an XADD command to the 'transactions' stream
            return $args[0] === 'XADD' && $args[1] === 'transactions';
        }))
        ->andReturn('12345-0'); // Return a dummy Redis Stream ID

    // 2. Run the command
    $this->artisan('sentinel:stream --limit=1')
        ->assertExitCode(0);
});

it('streams exactly one transaction when limit is set', function () {
    LRedis::shouldReceive('executeRaw')->once();

    // No more manual property flipping or environment checks
    $this->artisan('sentinel:stream --limit=1')
         ->assertExitCode(0)
         ->expectsOutput('Limit reached. Powering down.');
});

it('generates unique transactions on every yield', function () {
    $command = new StreamTransactions();

    // Using reflection to test the private generator
    $reflection = new ReflectionMethod($command, 'transactionGenerator');
    $reflection->setAccessible(true);
    $generator = $reflection->invoke($command);

    $t1 = $generator->current();
    $generator->next();
    $t2 = $generator->current();

    expect($t1['id'])->not->toBe($t2['id']);
    expect($t1['merchant'])->toBeIn(config('sentinel.simulation.merchants'));
    expect($t1['currency'])->toBeIn(config('sentinel.simulation.currencies'));
});
