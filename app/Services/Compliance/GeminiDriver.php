<?php

namespace App\Services\Compliance;

use App\Contracts\ComplianceDriver;
use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiDriver implements ComplianceDriver
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorCacheService $vectorCache,
    ) {}

    public function analyze(array $data): array
    {
        $query        = $this->buildQueryText($data);
        $policyChunks = $this->fetchPolicyContext($query);
        $prompt       = $this->buildPrompt($data, $policyChunks);
        $raw          = $this->callGeminiFlash($prompt);

        return $this->parseResponse($raw);
    }

    private function buildQueryText(array $data): string
    {
        return sprintf(
            'Anomaly detected: status=%s, metric_value=%s, anomaly_score=%s, source=%s',
            $data['status']        ?? 'unknown',
            $data['metric_value']  ?? 'N/A',
            $data['anomaly_score'] ?? 'N/A',
            $data['source_id']     ?? 'unknown',
        );
    }

    private function fetchPolicyContext(string $query): array
    {
        try {
            $vector = $this->embedding->embed($query);
            return $this->vectorCache->searchNamespace($vector, 'policies', 0.70, 3);
        } catch (\Throwable $e) {
            Log::warning('GeminiDriver: policy RAG failed, proceeding without context', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function buildPrompt(array $data, array $policyChunks): string
    {
        $policyText = empty($policyChunks)
            ? 'No specific policy context retrieved.'
            : collect($policyChunks)
                ->map(fn ($c) => '- ' . ($c['metadata']['text'] ?? json_encode($c['metadata'])))
                ->implode("\n");

        return <<<PROMPT
            You are a compliance audit system. An anomaly has been reported by the Synapse-L4 telemetry layer.

            Anomaly details:
            - Status: {$data['status']}
            - Metric value: {$data['metric_value']}
            - Anomaly score: {$data['anomaly_score']}
            - Source ID: {$data['source_id']}
            - Emitted at: {$data['emitted_at']}

            Relevant compliance policy context:
            {$policyText}

            Produce a structured compliance audit narrative. Respond ONLY with valid JSON matching this schema exactly:
            {
              "narrative": "<one or two sentence audit summary>",
              "risk_level": "<low|medium|high|critical>",
              "policy_refs": ["<policy id or title>"],
              "confidence": <float 0.0-1.0>
            }
            PROMPT;
    }

    private function callGeminiFlash(string $prompt): string
    {
        $apiKey = config('services.gemini.api_key');
        $url    = config('services.gemini.flash_url');

        $response = Http::timeout(15)
            ->retry(2, 200, throw: false)
            ->post($url . '?key=' . $apiKey, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (!$response->successful()) {
            Log::warning('GeminiDriver: Flash API call failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('GeminiDriver: Flash API call failed: ' . $response->body());
        }

        return $response->json('candidates.0.content.parts.0.text') ?? '';
    }

    private function parseResponse(string $raw): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean);

        $decoded = json_decode(trim($clean), true);

        if (!is_array($decoded) || !isset($decoded['narrative'])) {
            Log::warning('GeminiDriver: unexpected response shape', ['raw' => $raw]);
            return [
                'narrative'   => null,
                'risk_level'  => 'unknown',
                'policy_refs' => [],
                'confidence'  => 0.0,
            ];
        }

        return [
            'narrative'   => (string) ($decoded['narrative']   ?? ''),
            'risk_level'  => (string) ($decoded['risk_level']  ?? 'unknown'),
            'policy_refs' => (array)  ($decoded['policy_refs'] ?? []),
            'confidence'  => (float)  ($decoded['confidence']  ?? 0.0),
        ];
    }
}
