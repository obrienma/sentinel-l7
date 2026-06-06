<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

class OtelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TracerProviderInterface::class, function () {
            $endpoint  = rtrim(config('otel.endpoint'), '/') . '/v1/traces';
            $transport = (new OtlpHttpTransportFactory())->create($endpoint, 'application/x-protobuf');
            $exporter  = new SpanExporter($transport);
            $processor = new BatchSpanProcessor($exporter);

            $resource = ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => config('otel.service_name'),
            ]));

            return new TracerProvider([$processor], null, $resource);
        });
    }
}
