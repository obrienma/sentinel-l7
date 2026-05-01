<?php

namespace App\Console\Commands;

use App\Services\EmbeddingService;
use App\Services\VectorCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SentinelIngest extends Command
{
    protected $signature = 'sentinel:ingest
                            {--path=policies : Directory containing .md policy files}
                            {--chunk-size=500 : Target word count per chunk}';

    protected $description = 'Chunk and embed policy .md files into the Upstash Vector policies namespace';

    private const NAMESPACE = 'policies';

    public function handle(EmbeddingService $embedding, VectorCacheService $vectorCache): int
    {
        $path = base_path($this->option('path'));

        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return self::FAILURE;
        }

        $files = glob("{$path}/*.md");

        if (empty($files)) {
            $this->warn("No .md files found in {$path}");
            return self::SUCCESS;
        }

        $chunkSize  = (int) $this->option('chunk-size');
        $totalDocs  = 0;
        $totalFails = 0;

        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $this->line("Ingesting: <fg=blue>{$filename}</>");

            $chunks = $this->chunk(file_get_contents($file), $chunkSize);
            $bar    = $this->output->createProgressBar(count($chunks));
            $bar->start();

            foreach ($chunks as $index => $text) {
                $id = "{$filename}_{$index}";

                try {
                    $vector = $embedding->embed($text);
                    $vectorCache->upsertNamespace($id, $vector, [
                        'text'   => $text,
                        'source' => $filename,
                        'chunk'  => $index,
                    ], self::NAMESPACE);

                    $totalDocs++;
                } catch (\Throwable $e) {
                    Log::warning('sentinel:ingest chunk failed', [
                        'id'    => $id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->newLine();
                    $this->warn("  Failed chunk {$id}: {$e->getMessage()}");
                    $totalFails++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $epoch = $this->computePolicyEpoch($files);
        Cache::forever('sentinel_policy_epoch', $epoch);

        $this->info("Done. {$totalDocs} chunks indexed, {$totalFails} failed.");
        $this->line("Policy epoch: <fg=cyan>{$epoch}</>");

        return $totalFails === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Stable hash of the policy corpus — changes when any file is added, removed, or modified.
     * Sorted before hashing so file discovery order doesn't affect the result.
     */
    private function computePolicyEpoch(array $files): string
    {
        $hashes = array_map('md5_file', $files);
        sort($hashes);
        return md5(implode(',', $hashes));
    }

    /**
     * Split text into chunks of approximately $targetWords words,
     * breaking only on paragraph boundaries.
     *
     * @return string[]
     */
    private function chunk(string $text, int $targetWords): array
    {
        $paragraphs = preg_split('/\n{2,}/', trim($text));
        $chunks     = [];
        $current    = [];
        $wordCount  = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $words      = str_word_count($paragraph);
            $wordCount += $words;
            $current[]  = $paragraph;

            if ($wordCount >= $targetWords) {
                $chunks[]  = implode("\n\n", $current);
                $current   = [];
                $wordCount = 0;
            }
        }

        if (!empty($current)) {
            $chunks[] = implode("\n\n", $current);
        }

        return $chunks ?: [$text];
    }
}
