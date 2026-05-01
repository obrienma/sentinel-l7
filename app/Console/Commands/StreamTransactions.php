<?php
namespace App\Console\Commands;

use App\Services\TransactionStreamService;
use Illuminate\Console\Command;

class StreamTransactions extends Command
{
    protected $signature = 'sentinel:stream {--speed=1000} {--limit=10}';

    protected $description = "Stream demo transactions to Redis Streams";

    private bool $shouldStop = false;

    public function handle(TransactionStreamService $stream): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT,  fn () => $this->shouldStop = true);
        }

        $limit = (int) $this->option('limit');
        $count = 0;

        $this->info("Sentinel-L7: Monitoring layers" . ($limit > 0 ? " (Limit: $limit)" : ""));

        foreach ($stream->generate() as $transaction) {
            if ($this->shouldStop) {
                $this->info("Signal received. Powering down.");
                break;
            }

            if ($stream->publish($transaction)) {
                $this->line("<fg=cyan>Streamed:</> {$transaction['merchant']} | {$transaction['amount']}");
            } else {
                $this->warn("Duplicate skipped: {$transaction['id']}");
            }
            $count++;

            if ($limit > 0 && $count >= $limit) {
                $this->info("Limit reached. Powering down.");
                break;
            }

            usleep((int)$this->option('speed') * 1000);
        }
    }
}
