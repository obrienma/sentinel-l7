<?php
namespace App\Services;

class ThreatAnalysisService
{
    public function analyze(array $transaction): ThreatResult
    {
        $threshold = config('sentinel.thresholds.high_risk', 400.00);

        if ($transaction['amount'] > $threshold) {
            return ThreatResult::threat(
                "High value transaction at {$transaction['merchant']} ($" . number_format($transaction['amount'], 2) . ")",
                $transaction
            );
        }

        return ThreatResult::clear($transaction);
    }
}

class ThreatResult
{
    public function __construct(
        public readonly bool   $isThreat,
        public readonly string $message,
        public readonly array  $transaction,
    ) {}

    public static function threat(string $message, array $transaction): self
    {
        return new self(true, $message, $transaction);
    }

    public static function clear(array $transaction): self
    {
        $message = "Layer 7 Clear: {$transaction['merchant']} - OK";
        return new self(false, $message, $transaction);
    }
}
