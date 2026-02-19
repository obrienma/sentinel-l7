<?php

namespace App\Console\Commands;

use App\Services\ThreatAnalysisService;
use App\Services\TransactionStreamService;
use Illuminate\Console\Command;

class WatchTransactions extends Command
{
    protected $signature = 'sentinel:watch';
    protected $description = 'Monitor the L7 layer for suspicious activity';

    public function handle(TransactionStreamService $stream, ThreatAnalysisService $analyzer): void
    {
        $this->info('Sentinel-L7: Watcher initialized...');

        while (true) {
            foreach ($stream->read('$') as $message) {
                $data = json_decode($message[1][1], true);
                $result = $analyzer->analyze($data);

                if ($result->isThreat) {
                    $this->error("!!! THREAT DETECTED: {$result->message}");
                    // todo: Trigger an Inertia Event or Broadcast to dashboard
                } else {
                    $this->line($result->message);
                }
            }
        }
    }
}
