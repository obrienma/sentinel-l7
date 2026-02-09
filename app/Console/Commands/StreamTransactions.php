<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis as LRedis;
use Illuminate\Support\Str;

class StreamTransactions extends Command
{
    // Added {--limit=} for production safety and testing
    protected $signature = 'sentinel:stream {--speed=1000} {--limit=10}';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $count = 0;

        $this->info("Sentinel-L7: Monitoring layers" . ($limit > 0 ? " (Limit: $limit)" : ""));

        foreach ($this->transactionGenerator() as $transaction) {
            $this->sendToUpstash($transaction);
            $this->line("<fg=cyan>Streamed:</> {$transaction['merchant']} | {$transaction['amount']}");
            $count++;

            // Exit if limit is reached (0 means infinite)
            if ($limit > 0 && $count >= $limit) {
                $this->info("Limit reached. Powering down.");
                break;
            }

            usleep((int)$this->option('speed') * 1000);
        }
    }

    private function transactionGenerator(): \Generator
    {
        $merchants = config('sentinel.simulation.merchants');
        $currencies = config('sentinel.simulation.currencies');
        while (true) {
            yield [
                'id'       => Str::uuid()->toString(),
                'merchant' => $merchants[array_rand($merchants)],
                'currency' => $currencies[array_rand($currencies)],
                'amount'   => random_int(100, 50000) / 100,
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    private function sendToUpstash(array $data): void
    {
        LRedis::executeRaw([
            'XADD', 'transactions', 'MAXLEN', '~', '1000', '*', 'data', json_encode($data)
        ]);
    }
}
