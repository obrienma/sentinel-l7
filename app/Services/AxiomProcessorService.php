<?php

namespace App\Services;

use App\Contracts\ComplianceDriver;
use App\Models\ComplianceEvent;
use Illuminate\Support\Facades\Log;

class AxiomProcessorService
{
    public function __construct(
        private readonly ComplianceDriver $driver,
    ) {}

    /**
     * Process a single Axiom payload from the synapse:axioms stream.
     *
     * Routes to AI analysis when anomaly_score exceeds AXIOM_AUDIT_THRESHOLD.
     * Always persists a ComplianceEvent to Postgres — no Axiom is silently dropped.
     *
     * TODO: broadcast processed Axiom to dashboard feed (Redis list) once
     *       Flags/Compliance nav pages are built.
     *
     * @param  array $data  Decoded Axiom: {status, metric_value, anomaly_score, source_id, emitted_at}
     * @return array{source_id: string, routed_to_ai: bool, risk_level: string, narrative: string|null, elapsed_ms: float}
     */
    public function process(array $data): array
    {
        $start     = microtime(true);
        $threshold = (float) config('sentinel.axiom_threshold', 0.8);
        $score     = (float) ($data['anomaly_score'] ?? 0.0);
        $sourceId  = $data['source_id'] ?? 'unknown';

        if ($score > $threshold) {
            return $this->routeToAi($data, $sourceId, $start);
        }

        return $this->recordSubThreshold($data, $sourceId, $start);
    }

    private function routeToAi(array $data, string $sourceId, float $start): array
    {
        $result = [
            'narrative'   => null,
            'risk_level'  => 'unknown',
            'policy_refs' => [],
            'confidence'  => 0.0,
        ];

        try {
            $result = $this->driver->analyze($data);
        } catch (\Throwable $e) {
            Log::error('AxiomProcessorService: AI analysis failed', [
                'source_id' => $sourceId,
                'error'     => $e->getMessage(),
            ]);
        }

        ComplianceEvent::create([
            'source_id'       => $sourceId,
            'status'          => $data['status']        ?? null,
            'metric_value'    => $data['metric_value']  ?? null,
            'anomaly_score'   => $data['anomaly_score'] ?? null,
            'emitted_at'      => $data['emitted_at']    ?? null,
            'routed_to_ai'    => true,
            'audit_narrative' => $result['narrative'],
            'driver_used'     => config('sentinel.ai_driver'),
        ]);

        return [
            'source_id'    => $sourceId,
            'routed_to_ai' => true,
            'risk_level'   => $result['risk_level'],
            'narrative'    => $result['narrative'],
            'elapsed_ms'   => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    private function recordSubThreshold(array $data, string $sourceId, float $start): array
    {
        ComplianceEvent::create([
            'source_id'       => $sourceId,
            'status'          => $data['status']        ?? null,
            'metric_value'    => $data['metric_value']  ?? null,
            'anomaly_score'   => $data['anomaly_score'] ?? null,
            'emitted_at'      => $data['emitted_at']    ?? null,
            'routed_to_ai'    => false,
            'audit_narrative' => null,
            'driver_used'     => null,
        ]);

        return [
            'source_id'    => $sourceId,
            'routed_to_ai' => false,
            'risk_level'   => 'low',
            'narrative'    => null,
            'elapsed_ms'   => round((microtime(true) - $start) * 1000, 2),
        ];
    }
}
