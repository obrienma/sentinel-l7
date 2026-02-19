<?php
namespace App\Services;

use Illuminate\Support\Facades\Redis as LRedis;
use Illuminate\Support\Str;

class TransactionStreamService
{
    private const STREAM_KEY = 'transactions';
    private const STREAM_MAXLEN = '1000';

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

    public function publish(array $data): void
    {
        LRedis::executeRaw([
            'XADD', self::STREAM_KEY, 'MAXLEN', '~', self::STREAM_MAXLEN, '*', 'data', json_encode($data)
        ]);
    }

    /**
     * Block-reads new messages from the stream.
     * Pass '$' on first call to receive only new messages.
     *
     * @return array<int, array> List of raw messages
     */
    public function read(string $lastId = '$'): array
    {
        $results = LRedis::executeRaw(['XREAD', 'BLOCK', '0', 'STREAMS', self::STREAM_KEY, $lastId]);

        if (!$results) {
            return [];
        }

        return $results[0][1];
    }
}
