<?php

namespace App\Contracts;

interface ComplianceDriver
{
    /**
     * Analyze an Axiom payload and return a compliance audit result.
     *
     * @param  array  $data  Axiom payload: {status, metric_value, anomaly_score, source_id, emitted_at}
     * @return array{narrative: string|null, risk_level: string, policy_refs: array, confidence: float}
     */
    public function analyze(array $data): array;

    /**
     * Analyze a transaction for compliance risk, grounded in policy RAG.
     *
     * @param  array  $data  Transaction payload: {id, merchant, merchant_name, amount, currency}
     * @return array{narrative: string|null, risk_level: string, policy_refs: array, confidence: float}
     */
    public function analyzeTransaction(array $data): array;
}
