<?php
namespace App\Services;

use Illuminate\Support\Facades\Redis as LRedis;
use Illuminate\Support\Str;

class TransactionStreamService
{
    private const STREAM_KEY = 'transactions';
    private const STREAM_MAXLEN = '1000';
    private const GROUP = 'sentinel-consumers';

    public function generate(): \Generator
    {
        $merchants = config('sentinel.simulation.merchants');
        $currencies = config('sentinel.simulation.currencies');

        while (true) {
            yield [
                'id'        => Str::uuid()->toString(),
                'merchant'  => $merchants[array_rand($merchants)],
                'currency'  => $currencies[array_rand($currencies)],
                'amount'    => random_int(100, 50000) / 100,
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Publish a transaction to the stream, skipping duplicates via a 24h idempotency key.
     *
     * @return bool True if published, false if duplicate.
     */
    public function publish(array $data): bool
    {
        // Use Redis SETNX (Set if Not Exists) as a 24-hour idempotency key
        $isNew = LRedis::set("idemp:{$data['id']}", 'processed', 'EX', 86400, 'NX');

        if (!$isNew) {
            return false;
        }

        LRedis::executeRaw([
            'XADD', self::STREAM_KEY, 'MAXLEN', '~', self::STREAM_MAXLEN, '*', 'data', json_encode($data)
        ]);

        return true;
    }

    /**
     * Current stream depth (XLEN). Used by the producer as a backpressure signal.
     */
    public function depth(): int
    {
        return (int) LRedis::executeRaw(['XLEN', self::STREAM_KEY]);
    }

    /**
     * Create the consumer group if it does not already exist (MKSTREAM creates
     * the stream key if absent so workers can start before a producer writes).
     */
    public function ensureConsumerGroup(): void
    {
        try {
            LRedis::executeRaw(['XGROUP', 'CREATE', self::STREAM_KEY, self::GROUP, '$', 'MKSTREAM']);
        } catch (\Predis\Response\ServerException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * Read one new (undelivered) message for this consumer via XREADGROUP.
     * Blocks up to 5 seconds so the worker can return and run the next
     * XAUTOCLAIM pass even when the stream is idle. COUNT 1 prevents a deep
     * stream from being drained into a single batch.
     *
     * @return array{messages: array}
     */
    public function readGroup(string $consumer): array
    {
        $results = LRedis::executeRaw([
            'XREADGROUP', 'GROUP', self::GROUP, $consumer,
            'COUNT', '1', 'BLOCK', '5000',
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

        // XPENDING (range form) returns [[id, consumer, idle-ms, delivery-count], ...]
        return isset($result[0][3]) ? (int) $result[0][3] : 0;
    }
}
