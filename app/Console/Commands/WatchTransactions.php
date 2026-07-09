<?php
namespace App\Console\Commands;

use App\Services\TransactionProcessorService;
use App\Services\TransactionStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WatchTransactions extends Command
{
    protected $signature = 'sentinel:watch {--consumer=worker-1 : Consumer name for this worker instance}';
    protected $description = 'Monitor the L7 layer for suspicious activity';

    public function handle(
        TransactionStreamService    $stream,
        TransactionProcessorService $processor,
    ): void {
        $consumer      = $this->option('consumer');
        $idleMs        = (int) config('sentinel.reclaim.idle_ms');
        $deliveryLimit = (int) config('sentinel.reclaim.delivery_count_limit');

        $this->info("Sentinel-L7: Watcher initialized (consumer={$consumer})...");

        $stream->ensureConsumerGroup();

        while (true) {
            foreach ($stream->autoClaim($consumer, $idleMs) as $streamMsg) {
                $msgId = $streamMsg[0];

                if ($stream->deliveryCount($msgId) >= $deliveryLimit) {
                    Log::error('sentinel:watch dead-letter — delivery count exceeded', [
                        'stream'         => 'transactions',
                        'message_id'     => $msgId,
                        'delivery_limit' => $deliveryLimit,
                        'consumer'       => $consumer,
                    ]);
                    $stream->ack($msgId);

                    continue;
                }

                $this->line("<fg=yellow>⚠️  Reclaimed TXN {$msgId}</>");
                $this->processMessage($streamMsg, $processor);
                $stream->ack($msgId);
            }

            $read = $stream->readGroup($consumer);
            foreach ($read['messages'] as $streamMsg) {
                $this->processMessage($streamMsg, $processor);
                $stream->ack($streamMsg[0]);
            }

            $stream->writeLagKey($stream->pendingCount());
        }
    }

    private function processMessage(array $streamMsg, TransactionProcessorService $processor): void
    {
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
            'duplicate'  => "<fg=cyan>♻️  Duplicate — skipped [{$result['elapsed_ms']}ms]</>",
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
