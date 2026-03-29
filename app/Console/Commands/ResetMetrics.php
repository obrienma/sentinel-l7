<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ResetMetrics extends Command
{
    protected $signature = 'sentinel:reset-metrics';

    protected $description = 'Reset all dashboard metrics counters';

    public function handle(): void
    {
        $keys = ['cache_hit', 'cache_miss', 'fallback'];

        foreach ($keys as $key) {
            Cache::forget("sentinel_metrics_{$key}_count");
            Cache::forget("sentinel_metrics_{$key}_time");
        }
        Cache::forget('sentinel_metrics_threat_count');

        $this->info('Metrics reset.');
    }
}
