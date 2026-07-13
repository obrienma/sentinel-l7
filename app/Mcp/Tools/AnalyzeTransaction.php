<?php

namespace App\Mcp\Tools;

use App\Services\TransactionProcessorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AnalyzeTransaction extends Tool
{
    /** ComplianceManager driver names — see App\Services\ComplianceManager. */
    const DRIVERS = ['gemini', 'openrouter', 'ollama', 'vertexai'];

    protected string $description = <<<'MARKDOWN'
        Analyze a financial transaction for compliance violations (AML, HIPAA, GDPR, BSA).
        Returns risk level, threat flag, compliance message, and pipeline source (cache_hit / cache_miss / fallback / driver_override).
        Near-identical transactions are served from the semantic vector cache without re-running analysis.
        Pass "driver" to force a specific provider (gemini / openrouter / ollama / vertexai) instead of the
        configured default — bypasses the semantic cache entirely and never falls back to the
        rule-based analyzer on failure, for cross-provider comparison use cases.
    MARKDOWN;

    public function handle(Request $request, TransactionProcessorService $processor): Response
    {
        $data = $request->validate([
            'id' => 'sometimes|string',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'merchant' => 'required|string',
            'type' => 'sometimes|string',
            'category' => 'sometimes|string',
            'driver' => ['sometimes', Rule::in(self::DRIVERS)],
        ]);

        // 'driver' is a routing directive, not a transaction field — pull it out
        // of $data before it reaches the fingerprint/persistence layer.
        $driverOverride = Arr::pull($data, 'driver');

        $result = $processor->process($data, observe: false, driverOverride: $driverOverride);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->required()->description('Transaction amount'),
            'currency' => $schema->string()->required()->description('ISO 4217 currency code, e.g. USD'),
            'merchant' => $schema->string()->required()->description('Merchant or counterparty name'),
            'type' => $schema->string()->nullable()->description('Transaction type, e.g. purchase, transfer'),
            'category' => $schema->string()->nullable()->description('Merchant category, e.g. gas_station'),
            'id' => $schema->string()->nullable()->description('Optional transaction ID for cache keying'),
            'driver' => $schema->string()->nullable()->description('Force a specific provider: gemini, openrouter, ollama, or vertexai'),
        ];
    }
}
