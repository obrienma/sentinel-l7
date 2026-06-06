<?php

namespace App\Console\Commands;

use App\Services\AxiomProcessorService;
use App\Services\AxiomStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WatchAxioms extends Command
{
    protected $signature = 'sentinel:watch-axioms {--consumer=axiom-worker-1 : Consumer name for this worker instance}';

    protected $description = 'Consume Synapse-L4 Axioms from the synapse:axioms Redis stream';

    public function handle(
        AxiomStreamService $stream,
        AxiomProcessorService $processor,
    ): void {
        $consumer      = $this->option('consumer');
        $idleMs        = (int) config('sentinel.reclaim.idle_ms');
        $deliveryLimit = (int) config('sentinel.reclaim.delivery_count_limit');

        $this->info("Sentinel-L7: Axiom watcher initialized (consumer={$consumer})...");

        $stream->ensureConsumerGroup();

        while (true) {
            foreach ($stream->autoClaim($consumer, $idleMs) as $streamMsg) {
                $msgId = $streamMsg[0];

                if ($stream->deliveryCount($msgId) >= $deliveryLimit) {
                    Log::error('sentinel:watch-axioms dead-letter — delivery count exceeded', [
                        'stream'         => 'synapse:axioms',
                        'message_id'     => $msgId,
                        'delivery_limit' => $deliveryLimit,
                        'consumer'       => $consumer,
                    ]);
                    $stream->ack($msgId);

                    continue;
                }

                $this->line("<fg=yellow>⚠️  Reclaimed AXIOM {$msgId}</>");
                $this->processMessage($streamMsg, $stream, $processor);
                $stream->ack($msgId);
            }

            $read = $stream->readGroup($consumer);
            foreach ($read['messages'] as $streamMsg) {
                $this->processMessage($streamMsg, $stream, $processor);
                $stream->ack($streamMsg[0]);
            }
        }
    }

    private function processMessage(array $streamMsg, AxiomStreamService $stream, AxiomProcessorService $processor): void
    {
        $parsed      = $stream->parseFields($streamMsg[1]);
        $data        = $parsed['fields'];
        $traceparent = $parsed['traceparent'];

        $sourceId = $data['source_id']     ?? '?';
        $score    = $data['anomaly_score'] ?? '?';
        $status   = $data['status']        ?? '?';

        $this->line('');
        $this->line("<fg=blue>──── AXIOM {$sourceId}</>");
        $this->line("<fg=blue>     status={$status} | score={$score}</>");

        $outcome = $processor->process($data, $traceparent);

        if ($outcome['routed_to_ai']) {
            $this->line("<fg=yellow>🤖 AI [{$outcome['elapsed_ms']}ms] risk={$outcome['risk_level']}</>");

            if ($outcome['narrative']) {
                $this->line($outcome['narrative']);
            }
        } else {
            $this->line("<fg=green>✅ Sub-threshold [{$outcome['elapsed_ms']}ms] — logged</>");
        }
    }
}
