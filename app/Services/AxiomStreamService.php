<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis as LRedis;

class AxiomStreamService
{
    private const STREAM_KEY = 'synapse:axioms';

    private const STREAM_MAXLEN = '500';

    private const GROUP = 'axiom-workers';

    /**
     * Create the consumer group if it does not already exist.
     * Uses MKSTREAM so the stream key is created if absent.
     */
    public function ensureConsumerGroup(): void
    {
        try {
            LRedis::executeRaw(['XGROUP', 'CREATE', self::STREAM_KEY, self::GROUP, '$', 'MKSTREAM']);
        } catch (\Predis\Response\ServerException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
            // Group already exists — normal on restart.
        }
    }

    /**
     * Read new (undelivered) Axioms for this consumer via XREADGROUP.
     * Blocks up to 2 seconds. Unacknowledged messages enter the PEL.
     *
     * @return array{messages: array, cursor: null}
     */
    public function readGroup(string $consumer): array
    {
        $results = LRedis::executeRaw([
            'XREADGROUP', 'GROUP', self::GROUP, $consumer,
            'COUNT', '10', 'BLOCK', '2000',
            'STREAMS', self::STREAM_KEY, '>',
        ]);

        if (! $results) {
            return ['messages' => []];
        }

        return ['messages' => $results[0][1]];
    }

    /**
     * Acknowledge a message, removing it from the PEL.
     */
    public function ack(string $messageId): void
    {
        LRedis::executeRaw(['XACK', self::STREAM_KEY, self::GROUP, $messageId]);
    }

    /**
     * Claim pending messages that have been idle longer than $minIdleMs.
     * Uses XAUTOCLAIM — atomically reassigns up to 10 messages at a time.
     *
     * @return array Raw stream message entries [[id, [field, value, ...]], ...]
     */
    public function claimPending(string $consumer, int $minIdleMs = 60_000): array
    {
        $result = LRedis::executeRaw([
            'XAUTOCLAIM', self::STREAM_KEY, self::GROUP, $consumer,
            (string) $minIdleMs, '0-0', 'COUNT', '10',
        ]);

        // XAUTOCLAIM returns [next-start-id, [[id, [fields...]], ...], [deleted-ids]]
        return $result[1] ?? [];
    }

    /**
     * Parse the flat [field, value, field, value, ...] list returned by Redis
     * into an associative array, casting numeric Axiom fields.
     */
    public function parseFields(array $flat): array
    {
        $data = [];
        for ($i = 0; $i < count($flat); $i += 2) {
            $data[$flat[$i]] = $flat[$i + 1];
        }

        if (array_key_exists('anomaly_score', $data)) {
            $data['anomaly_score'] = (float) $data['anomaly_score'];
        }
        if (array_key_exists('metric_value', $data)) {
            $data['metric_value'] = (float) $data['metric_value'];
        }

        return $data;
    }

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
     * Block-reads new Axioms from the stream (plain XREAD, no consumer group).
     * Pass '$' on first call to receive only new messages.
     *
     * @param  string  $lastId  Stream cursor.
     * @return array{messages: array, cursor: string}
     */
    public function read(string $lastId = '$'): array
    {
        $results = LRedis::executeRaw(['XREAD', 'BLOCK', '2000', 'STREAMS', self::STREAM_KEY, $lastId]);

        if (! $results) {
            return ['messages' => [], 'cursor' => $lastId];
        }

        $messages = $results[0][1];

        return [
            'messages' => $messages,
            'cursor' => end($messages)[0],
        ];
    }
}
