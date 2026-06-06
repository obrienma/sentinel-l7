<?php

return [
    'endpoint'     => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
    'service_name' => env('OTEL_SERVICE_NAME', 'sentinel-l7'),
];
