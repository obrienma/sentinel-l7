<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis as LRedis;

class AxiomStreamService
{
    private const STREAM_KEY    = 'synapse:axioms';
    private const STREAM_MAXLEN = '500';

    /**
     * Publish an Axiom to the stream.
     */
    public function publish(array $data): bool
    {
        LRedis::executeRaw([
            'XADD', self::STREAM_KEY, 'MAXLEN', '~', self::STREAM_MAXLEN, '*', 'data', json_encode($data),
        ]);

        return true;
    }

    /**
     * Block-reads new Axioms from the stream.
     * Pass '$' on first call to receive only new messages.
     *
     * @param  string $lastId  Stream cursor — pass '$' initially, then the last message ID on subsequent calls.
     * @return array{messages: array, cursor: string}
     */
    public function read(string $lastId = '$'): array
    {
        $results = LRedis::executeRaw(['XREAD', 'BLOCK', '2000', 'STREAMS', self::STREAM_KEY, $lastId]);

        if (!$results) {
            return ['messages' => [], 'cursor' => $lastId];
        }

        $messages = $results[0][1];

        return [
            'messages' => $messages,
            'cursor'   => end($messages)[0],
        ];
    }
}
