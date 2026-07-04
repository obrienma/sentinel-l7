<?php

namespace App\Console\Commands;

use App\Services\TransactionStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportGroundTruth extends Command
{
    protected $signature = 'sentinel:export-ground-truth {--count=200} {--output=}';

    protected $description = 'Export pre-AI labeled transactions as an eval ground-truth dataset';

    public function handle(TransactionStreamService $stream): int
    {
        $count = (int) $this->option('count');
        $generator = $stream->generate();

        $examples = [];
        for ($i = 0; $i < $count; $i++) {
            $tx = $generator->current();
            $generator->next();

            $examples[] = [
                'input' => [
                    'id' => $tx['id'],
                    'amount' => $tx['amount'],
                    'currency' => $tx['currency'],
                    'merchant' => $tx['merchant'],
                    'category' => $tx['category'],
                ],
                // Ground truth only ever knows the binary is_threat flag pre-AI
                // (config('sentinel.simulation.merchants')), not a graded
                // risk_level. 'high'/'low' mirrors the same collapse
                // TransactionProcessorService already uses for its own
                // rule-based fallback (`$isThreat ? 'high' : 'low'`), so this
                // dataset stays consistent with Sentinel-L7's own taxonomy
                // convention rather than inventing a new one.
                'expected_label' => $tx['is_threat'] ? 'high' : 'low',
            ];
        }

        $payload = json_encode(['examples' => $examples], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $output = $this->option('output');
        if ($output) {
            File::put($output, $payload);
            $this->info("Exported {$count} ground-truth examples to {$output}");
        } else {
            $this->line($payload);
        }

        return self::SUCCESS;
    }
}
