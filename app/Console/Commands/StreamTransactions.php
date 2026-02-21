<?php
namespace App\Console\Commands;

use App\Services\TransactionStreamService;
use Illuminate\Console\Command;

class StreamTransactions extends Command
{
    protected $signature = 'sentinel:stream {--speed=1000} {--limit=10}';

    protected $description = "Stream demo transactions to Redis Streams";

    public function handle(TransactionStreamService $stream): void
    {
        $limit = (int) $this->option('limit');
        $count = 0;

        $this->info("Sentinel-L7: Monitoring layers" . ($limit > 0 ? " (Limit: $limit)" : ""));

        foreach ($stream->generate() as $transaction) {
            if ($stream->publish($transaction)) {
                $this->line("<fg=cyan>Streamed:</> [{$transaction['id']}] {$transaction['merchant']} | {$transaction['currency']} {$transaction['amount']}");
            } else {
                $this->warn("Duplicate skipped: {$transaction['id']}");
            }
            $count++;

            // Exit if limit is reached (0 means infinite)
            if ($limit > 0 && $count >= $limit) {
                $this->info("Limit reached. Powering down.");
                break;
            }

            usleep((int)$this->option('speed') * 1000);
        }
    }
}
