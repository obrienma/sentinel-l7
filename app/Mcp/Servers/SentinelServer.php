<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AnalyzeTransaction;
use App\Mcp\Tools\GetRecentTransactions;
use App\Mcp\Tools\SearchPolicies;
use Laravel\Mcp\Server;

class SentinelServer extends Server
{
    protected string $name = 'Sentinel L7';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Sentinel L7 is a real-time financial compliance monitoring system.

        Available tools:
        - analyze_transaction: Run a transaction through the full compliance pipeline (semantic cache → AML/GDPR/HIPAA analysis).
        - search_policies: Retrieve relevant regulatory policy chunks from the knowledge base by semantic query.
        - get_recent_transactions: Inspect the live feed of recently processed transactions and their threat status.

        Typical workflow: call search_policies first to understand applicable rules, then analyze_transaction to evaluate a specific transaction.
    MARKDOWN;

    protected array $tools = [
        AnalyzeTransaction::class,
        SearchPolicies::class,
        GetRecentTransactions::class,
    ];
}
