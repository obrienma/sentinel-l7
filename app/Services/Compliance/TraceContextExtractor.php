<?php

namespace App\Services\Compliance;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ContextInterface;

/**
 * Extracts W3C TraceContext from an incoming Redis Stream traceparent field.
 * Transport-layer concern — not part of the Axiom or ComplianceEvent domain (ADR-0024).
 */
class TraceContextExtractor
{
    public function extract(?string $traceparent): ContextInterface
    {
        $carrier = $traceparent !== null && $traceparent !== '' ? ['traceparent' => $traceparent] : [];

        return TraceContextPropagator::getInstance()->extract($carrier);
    }
}
