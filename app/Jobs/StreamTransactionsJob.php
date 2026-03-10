<?php

namespace App\Jobs;

use App\Services\TransactionProcessorService;
use App\Services\TransactionStreamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StreamTransactionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $count = 10) {}

    public function handle(TransactionStreamService $stream, TransactionProcessorService $processor): void
    {
        $published = 0;

        foreach ($stream->generate() as $transaction) {
            $stream->publish($transaction);
            $processor->process($transaction);

            if (++$published >= $this->count) {
                break;
            }
        }
    }
}
