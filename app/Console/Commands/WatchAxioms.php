<?php

namespace App\Console\Commands;

use App\Services\AxiomProcessorService;
use App\Services\AxiomStreamService;
use Illuminate\Console\Command;

class WatchAxioms extends Command
{
    protected $signature = 'sentinel:watch-axioms {--consumer=axiom-worker-1 : Consumer name for this worker instance}';

    protected $description = 'Consume Synapse-L4 Axioms from the synapse:axioms Redis stream';

    public function handle(
        AxiomStreamService $stream,
        AxiomProcessorService $processor,
    ): void {
        $consumer = $this->option('consumer');

        $this->info("Sentinel-L7: Axiom watcher initialized (consumer={$consumer})...");

        $stream->ensureConsumerGroup();

        while (true) {
            $read = $stream->readGroup($consumer);

            foreach ($read['messages'] as $streamMsg) {
                $msgId = $streamMsg[0];
                $data = $stream->parseFields($streamMsg[1]);

                $sourceId = $data['source_id'] ?? '?';
                $score = $data['anomaly_score'] ?? '?';
                $status = $data['status'] ?? '?';

                $this->line('');
                $this->line("<fg=blue>──── AXIOM {$sourceId}</>");
                $this->line("<fg=blue>     status={$status} | score={$score}</>");

                $outcome = $processor->process($data);
                $stream->ack($msgId);

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
    }
}
