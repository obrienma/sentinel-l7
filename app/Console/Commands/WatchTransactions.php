<?php
namespace App\Console\Commands;

use App\Services\TransactionProcessorService;
use App\Services\TransactionStreamService;
use Illuminate\Console\Command;

class WatchTransactions extends Command
{
    protected $signature = 'sentinel:watch';
    protected $description = 'Monitor the L7 layer for suspicious activity';

    public function handle(
        TransactionStreamService    $stream,
        TransactionProcessorService $processor,
    ): void {
        $this->info('Sentinel-L7: Watcher initialized...');

        while (true) {
            foreach ($stream->read('$') as $streamMsg) {
                $data     = json_decode($streamMsg[1][1], true);
                $txnId    = $data['id']       ?? '?';
                $merchant = $data['merchant'] ?? $data['merchant_name'] ?? '?';
                $amount   = isset($data['amount']) ? number_format((float) $data['amount'], 2) : '?';
                $currency = $data['currency'] ?? '';

                $this->line('');
                $this->line("<fg=blue>──── TXN {$txnId}</>");
                $this->line("<fg=blue>     {$merchant} | {$currency} {$amount}</>");

                $result = $processor->process($data);

                $tag = match($result['source']) {
                    'cache_hit'  => "<fg=green>✅ Cache hit [{$result['elapsed_ms']}ms]</>",
                    'cache_miss' => "<fg=yellow>❌ Cache miss [{$result['elapsed_ms']}ms]</>",
                    default      => "<fg=yellow>⚠️  Fallback [{$result['elapsed_ms']}ms]</>",
                };

                $this->line($tag);

                if ($result['is_threat']) {
                    $this->error("!!! THREAT: {$result['message']}");
                } else {
                    $this->line($result['message']);
                }
            }
        }
    }
}
