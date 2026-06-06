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
     * Claim pending messages idle longer than $minIdleMs via XAUTOCLAIM. Embedded
     * in each worker's loop so recovery is distributed — losing a worker does not
     * stop recovery (ADR-0022).
     *
     * @return array Raw stream message entries [[id, [field, value, ...]], ...]
     */
    public function autoClaim(string $consumer, int $minIdleMs, int $count = 10): array
    {
        $result = LRedis::executeRaw([
            'XAUTOCLAIM', self::STREAM_KEY, self::GROUP, $consumer,
            (string) $minIdleMs, '0-0', 'COUNT', (string) $count,
        ]);

        // XAUTOCLAIM returns [next-start-id, [[id, [fields...]], ...], [deleted-ids]]
        return $result[1] ?? [];
    }

    /**
     * Read the delivery count for a single message via XPENDING. Returns 0 if
     * the message is not in the PEL. Used by the worker loop to dead-letter
     * poison messages — XAUTOCLAIM itself does not return delivery counts.
     */
    public function deliveryCount(string $messageId): int
    {
        $result = LRedis::executeRaw([
            'XPENDING', self::STREAM_KEY, self::GROUP,
            'IDLE', '0', $messageId, $messageId, '1',
        ]);

        return isset($result[0][3]) ? (int) $result[0][3] : 0;
    }

    /**
     * Parse the flat [field, value, field, value, ...] list returned by Redis.
     * Returns Axiom fields separately from the transport-layer traceparent header
     * so callers can propagate trace context without it leaking into domain data.
     *
     * @return array{fields: array, traceparent: string|null}
     */
    public function parseFields(array $flat): array
    {
        $data = [];
        for ($i = 0; $i < count($flat); $i += 2) {
            $data[$flat[$i]] = $flat[$i + 1];
        }

        $traceparent = $data['traceparent'] ?? null;
        unset($data['traceparent']);

        if (array_key_exists('anomaly_score', $data)) {
            $data['anomaly_score'] = (float) $data['anomaly_score'];
        }
        if (array_key_exists('metric_value', $data)) {
            $data['metric_value'] = (float) $data['metric_value'];
        }

        return ['fields' => $data, 'traceparent' => $traceparent];
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
