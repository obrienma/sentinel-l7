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

    public function createTransactionFingerprint(array $transaction): string
    {
        // Create a semantic fingerprint of the transaction
        return implode(' | ', [
            "Amount: " . ($transaction['amount'] ?? 'N/A') . " " . ($transaction['currency'] ?? 'N/A'),
            "Type: " . ($transaction['type'] ?? 'N/A'),
            "Category: " . ($transaction['category'] ?? 'unknown'),
            "Time: " . (isset($transaction['timestamp']) ? date('H:i', strtotime($transaction['timestamp'])) : 'N/A'),
            "Merchant: " . ($transaction['merchant_name'] ?? 'N/A'),
        ]);
    }
}
