<?php

namespace App\Console\Commands;

use App\Services\AxiomProcessorService;
use App\Services\AxiomStreamService;
use Illuminate\Console\Command;

class ReclaimAxioms extends Command
{
    protected $signature = 'sentinel:reclaim-axioms';

    protected $description = 'Reclaim and reprocess stuck Axioms from the synapse:axioms PEL (XCLAIM recovery)';

    public function handle(
        AxiomStreamService $stream,
        AxiomProcessorService $processor,
    ): void {
        $this->info('Sentinel-L7: Axiom reclaimer initialized (idle threshold: 60s)...');

        $consumer = 'axiom-reclaimer';
        $stream->ensureConsumerGroup();

        while (true) {
            $pending = $stream->claimPending($consumer);

            if (empty($pending)) {
                sleep(30);

                continue;
            }

            foreach ($pending as $streamMsg) {
                $msgId = $streamMsg[0];
                $data = $stream->parseFields($streamMsg[1]);

                $sourceId = $data['source_id'] ?? '?';
                $score = $data['anomaly_score'] ?? '?';

                $this->line("<fg=yellow>⚠️  Reclaiming AXIOM {$sourceId} (score={$score})</>");

                $outcome = $processor->process($data);
                $stream->ack($msgId);

                $this->line("<fg=green>✅ Reclaimed [{$outcome['elapsed_ms']}ms]</>");
            }
        }
    }
}
