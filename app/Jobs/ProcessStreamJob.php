<?php

namespace App\Jobs;

use App\Services\TransactionProcessorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStreamJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $data) {}

    public function handle(TransactionProcessorService $processor): void
    {
        $processor->process($this->data);
    }
}
