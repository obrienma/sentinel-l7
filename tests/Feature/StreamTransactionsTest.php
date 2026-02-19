<?php
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
