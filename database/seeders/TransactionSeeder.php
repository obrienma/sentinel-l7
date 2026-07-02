<?php

namespace Database\Seeders;

use App\Services\TransactionProcessorService;
use App\Services\TransactionStreamService;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $count = 500;
        $generator = app(TransactionStreamService::class)->generate();
        $processor = app(TransactionProcessorService::class);
        $results = ['cache_hit' => 0, 'cache_miss' => 0, 'fallback' => 0, 'threats' => 0];

        $this->command->info("Running {$count} transactions through the pipeline...");
        $this->command->info('UPSTASH_VECTOR_SIMILARITY_THRESHOLD = '.config('services.upstash_vector.similarity_threshold'));

        for ($i = 0; $i < $count; $i++) {
            $tx = $generator->current();
            $generator->next();

            $result = $processor->process($tx);

            $results[$result['source']] = ($results[$result['source']] ?? 0) + 1;
            if ($result['is_threat']) {
                $results['threats']++;
            }

            if (($i + 1) % 50 === 0) {
                $this->command->line("  [{$i}/{$count}] hits={$results['cache_hit']} misses={$results['cache_miss']} threats={$results['threats']}");
            }
        }

        $hitRate = $results['cache_miss'] + $results['cache_hit'] > 0
            ? round($results['cache_hit'] / ($results['cache_hit'] + $results['cache_miss']) * 100, 1)
            : 0;

        $this->command->info('');
        $this->command->info('=== Benchmark Results ===');
        $this->command->table(
            ['Metric', 'Value'],
            [
                ['Total transactions',  $count],
                ['Cache hits',          $results['cache_hit']],
                ['Cache misses',        $results['cache_miss']],
                ['Fallbacks',           $results['fallback'] ?? 0],
                ['Cache hit rate',      $hitRate.'%'],
                ['Embedding API calls', $results['cache_miss'] + ($results['fallback'] ?? 0)],
                ['Threats detected',    $results['threats']],
                ['Threat rate',         round($results['threats'] / $count * 100, 1).'%'],
            ]
        );
    }
}
