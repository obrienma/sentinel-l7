<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    public function embed(string $text): array
    {
        $apiKey = config('services.gemini.api_key');
        $baseUrl = config('services.gemini.embedding_url')
            ?: 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->timeout(10)
            ->retry(3, 200, throw: false)
            ->post($baseUrl . '?key=' . $apiKey, [
                'content' => [
                    'parts' => [
                        ['text' => $text]
                    ],
                ],
                'output_dimensionality' => 1536,
            ]);

        if (!$response->successful()) {
            Log::warning('Gemini embedding failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Gemini embedding failed: ' . $response->body());
        }

        return $response->json('embedding.values');
    }

    private function amountTier(float $amount): string
    {
        return match(true) {
            $amount < 10    => 'micro',
            $amount < 100   => 'small',
            $amount < 500   => 'medium',
            $amount < 2000  => 'large',
            default         => 'very_large',
        };
    }

    public function createTransactionFingerprint(array $transaction): string
    {
        $amount = isset($transaction['amount']) ? (float) $transaction['amount'] : null;

        // Create a semantic fingerprint of the transaction
        $fingerprint = implode(' | ', [
            "Amount: " . ($amount !== null ? $this->amountTier($amount) : 'N/A') . " " . ($transaction['currency'] ?? 'N/A'),
            "Type: " . ($transaction['type'] ?? 'N/A'),
            "Category: " . ($transaction['category'] ?? 'unknown'),
            "Merchant: " . ($transaction['merchant_name'] ?? 'N/A'),
            "Time: " . (isset($transaction['timestamp']) ? match(true) {
                (int) date('G', strtotime($transaction['timestamp'])) < 6  => 'night',
                (int) date('G', strtotime($transaction['timestamp'])) < 12 => 'morning',
                (int) date('G', strtotime($transaction['timestamp'])) < 17 => 'afternoon',
                (int) date('G', strtotime($transaction['timestamp'])) < 21 => 'evening',
                default => 'night',
            } : 'N/A'),
        ]);

        Log::debug('[Sentinel] Fingerprint: ' . $fingerprint);

        return $fingerprint;
    }
}
