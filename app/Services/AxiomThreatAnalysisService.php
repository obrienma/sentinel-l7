<?php

namespace App\Services;

class AxiomThreatAnalysisService
{
    /**
     * Rule-based Tier 3 fallback for the Axiom pipeline — no AI involved.
     *
     * Only reached when Gemini/OpenRouter is unreachable for an Axiom whose
     * anomaly_score already exceeded config('sentinel.axiom_threshold'), so
     * the verdict is deterministic: flag it, and say why AI didn't weigh in.
     *
     * @return array{narrative: string, risk_level: string}
     */
    public function analyze(array $data): array
    {
        $threshold = (float) config('sentinel.axiom_threshold', 0.8);
        $score = (float) ($data['anomaly_score'] ?? 0.0);
        $domain = $data['domain'] ?? 'unspecified';

        return [
            'narrative' => "Rule-based fallback: anomaly score {$score} exceeds audit threshold {$threshold} for domain {$domain} — AI analysis unavailable.",
            'risk_level' => 'high',
        ];
    }
}
