<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    public function embed(string $text): array
    {
        $apiKey = config('services.gemini.api_key');

        // Using Gemini API for embeddings
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent?key=' . $apiKey, [
            'content' => [
                'parts' => [
                    ['text' => $text]
                ],
            ],
            "output_dimensionality" => 1536,
        ]);

        if (!$response->successful()) {
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
