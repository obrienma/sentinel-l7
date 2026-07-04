<?php

namespace App\Services;

use App\Contracts\ComplianceDriver;
use App\Models\ComplianceEvent;
use App\Services\Compliance\TraceContextExtractor;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

class AxiomProcessorService
{
    private TracerInterface $tracer;

    public function __construct(
        private readonly ComplianceDriver $driver,
        private readonly TraceContextExtractor $extractor = new TraceContextExtractor(),
        private readonly AxiomThreatAnalysisService $fallback = new AxiomThreatAnalysisService(),
        ?TracerProviderInterface $tracerProvider = null,
    ) {
        $this->tracer = ($tracerProvider ?? Globals::tracerProvider())
            ->getTracer('sentinel-l7');
    }

    /**
     * Process a single Axiom payload from the synapse:axioms stream.
     *
     * Routes to AI analysis when anomaly_score exceeds AXIOM_AUDIT_THRESHOLD.
     * Always persists a ComplianceEvent to Postgres — no Axiom is silently dropped.
     * The optional $traceparent continues the distributed trace from Synapse-L4.
     *
     * @param  array  $data  Decoded Axiom: {status, metric_value, anomaly_score, source_id, emitted_at, domain?}
     * @return array{source_id: string, routed_to_ai: bool, risk_level: string, narrative: string|null, domain: string|null, elapsed_ms: float}
     */
    public function process(array $data, ?string $traceparent = null): array
    {
        $start     = microtime(true);
        $threshold = (float) config('sentinel.axiom_threshold', 0.8);
        $score     = (float) ($data['anomaly_score'] ?? 0.0);
        $sourceId  = $data['source_id'] ?? 'unknown';
        $domain    = $data['domain'] ?? null;

        $parentContext = $this->extractor->extract($traceparent);
        $span  = $this->tracer->spanBuilder('axiom.process')->setParent($parentContext)->startSpan();
        $scope = $span->activate();

        try {
            $span->setAttributes([
                'source_id'     => $sourceId,
                'anomaly_score' => $score,
                'metric_value'  => (float) ($data['metric_value'] ?? 0.0),
                'domain'        => $domain ?? '',
                'status'        => $data['status'] ?? '',
                'threshold'     => $threshold,
            ]);

            if ($sourceId === 'unknown') {
                Log::warning('AxiomProcessorService: Axiom received without source_id', [
                    'anomaly_score' => $score,
                ]);
            }

            if ($sourceId !== 'unknown' && ComplianceEvent::where('source_id', $sourceId)->exists()) {
                Log::info('AxiomProcessorService: duplicate source_id — skipping AI call', [
                    'source_id' => $sourceId,
                ]);

                $elapsed = round((microtime(true) - $start) * 1000, 2);
                $span->setAttributes(['is_duplicate' => true, 'routed_to_ai' => false, 'elapsed_ms' => $elapsed]);

                return [
                    'source_id'    => $sourceId,
                    'routed_to_ai' => false,
                    'risk_level'   => 'skipped',
                    'narrative'    => null,
                    'domain'       => $domain,
                    'elapsed_ms'   => $elapsed,
                ];
            }

            $span->setAttribute('is_duplicate', false);

            if ($score > $threshold) {
                $span->setAttribute('routed_to_ai', true);
                $result = $this->routeToAi($data, $sourceId, $domain, $start);
            } else {
                $span->setAttribute('routed_to_ai', false);
                $result = $this->recordSubThreshold($data, $sourceId, $domain, $start);
            }

            $span->setAttributes(['risk_level' => $result['risk_level'], 'elapsed_ms' => $result['elapsed_ms']]);

            return $result;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    private function routeToAi(array $data, string $sourceId, ?string $domain, float $start): array
    {
        $result = [
            'narrative'   => null,
            'risk_level'  => 'unknown',
            'policy_refs' => [],
            'confidence'  => 0.0,
        ];
        $driverUsed = config('sentinel.ai_driver');

        $aiSpan  = $this->tracer->spanBuilder('axiom.ai_analysis')->startSpan();
        $aiScope = $aiSpan->activate();

        try {
            $result = $this->driver->analyze($data);
            $aiSpan->setAttributes([
                'ai.driver'           => config('sentinel.ai_driver'),
                'ai.confidence'       => (float) ($result['confidence'] ?? 0.0),
                'ai.policy_refs'      => count($result['policy_refs'] ?? []),
                'ai.narrative_length' => strlen($result['narrative'] ?? ''),
                'ai.risk_level'       => $result['risk_level'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $aiSpan->recordException($e);
            Log::error('AxiomProcessorService: AI analysis failed', [
                'source_id' => $sourceId,
                'error'     => $e->getMessage(),
            ]);

            // Tier 3 — rule-based fallback (ADR-0007 parity for the Axiom pipeline).
            $fallback = $this->fallback->analyze($data);
            $result['risk_level'] = $fallback['risk_level'];
            $result['narrative'] = $fallback['narrative'];
            $driverUsed = 'fallback';
        } finally {
            $aiSpan->end();
            $aiScope->detach();
        }

        $this->persist($sourceId, [
            'domain'          => $domain,
            'status'          => $data['status'] ?? null,
            'metric_value'    => $data['metric_value'] ?? null,
            'anomaly_score'   => $data['anomaly_score'] ?? null,
            'emitted_at'      => $data['emitted_at'] ?? null,
            'routed_to_ai'    => true,
            'audit_narrative' => $result['narrative'],
            'driver_used'     => $driverUsed,
        ]);

        return [
            'source_id'    => $sourceId,
            'routed_to_ai' => true,
            'risk_level'   => $result['risk_level'],
            'narrative'    => $result['narrative'],
            'domain'       => $domain,
            'elapsed_ms'   => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    private function recordSubThreshold(array $data, string $sourceId, ?string $domain, float $start): array
    {
        $subSpan  = $this->tracer->spanBuilder('axiom.sub_threshold')->startSpan();
        $subScope = $subSpan->activate();

        try {
            $this->persist($sourceId, [
                'domain'          => $domain,
                'status'          => $data['status'] ?? null,
                'metric_value'    => $data['metric_value'] ?? null,
                'anomaly_score'   => $data['anomaly_score'] ?? null,
                'emitted_at'      => $data['emitted_at'] ?? null,
                'routed_to_ai'    => false,
                'audit_narrative' => null,
                'driver_used'     => null,
            ]);
        } finally {
            $subSpan->end();
            $subScope->detach();
        }

        return [
            'source_id'    => $sourceId,
            'routed_to_ai' => false,
            'risk_level'   => 'low',
            'narrative'    => null,
            'domain'       => $domain,
            'elapsed_ms'   => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    private function persist(string $sourceId, array $fields): void
    {
        try {
            if ($sourceId !== 'unknown') {
                ComplianceEvent::firstOrCreate(['source_id' => $sourceId], $fields);
            } else {
                ComplianceEvent::create(['source_id' => $sourceId, ...$fields]);
            }
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Concurrent re-delivery: another worker already persisted this source_id.
            Log::info('AxiomProcessorService: duplicate source_id suppressed by DB constraint', [
                'source_id' => $sourceId,
            ]);
        }
    }
}
