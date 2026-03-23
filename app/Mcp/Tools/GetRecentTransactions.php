<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Redis;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetRecentTransactions extends Tool
{
    protected string $description = <<<'MARKDOWN'
        Retrieve the most recent transactions processed by the Sentinel L7 compliance pipeline.
        Returns entries from the live feed, newest first, including threat status, source (cache_hit / cache_miss / fallback), and elapsed processing time.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        $limit = $validated['limit'] ?? 20;
        $raw   = Redis::lrange('sentinel:recent_transactions', 0, $limit - 1);

        $transactions = collect($raw)
            ->map(fn (string $entry) => json_decode($entry, true))
            ->filter()
            ->values()
            ->all();

        return Response::json([
            'transactions' => $transactions,
            'count'        => count($transactions),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->nullable()->description('Number of recent transactions to return (default 20, max 50)'),
        ];
    }
}
