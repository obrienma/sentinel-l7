<?php

use App\Providers\OtelServiceProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;

uses(Tests\TestCase::class);

it('resolves TracerProviderInterface from the container without throwing', function () {
    config([
        'otel.endpoint'     => 'https://otel-collector.example.com',
        'otel.service_name' => 'sentinel-l7-test',
    ]);

    $provider = $this->app->make(TracerProviderInterface::class);

    expect($provider)->toBeInstanceOf(TracerProviderInterface::class);
});
