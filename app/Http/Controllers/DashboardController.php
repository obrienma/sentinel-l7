<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // TODO: scope all data queries by auth()->user()->tenant_id when multitenancy lands
        return Inertia::render('Dashboard', [
            'user' => [
                'name'  => auth()->user()->name,
                'email' => auth()->user()->email,
            ],
            'metrics'      => $this->metrics(),
            'recentTxns'   => $this->recentTransactions(),
        ]);
    }

    private function recentTransactions(): array
    {
        // LRANGE returns up to 20 most recent entries (newest first).
        // Each entry is a JSON string — decode into an array for Inertia.
        $raw = Redis::executeRaw(['LRANGE', 'sentinel:recent_transactions', 0, 19]);

        return array_map(fn($item) => json_decode($item, true), $raw ?? []);
    }

    private function metrics(): array
    {
        $hits      = (int) Cache::get('sentinel_metrics_cache_hit_count', 0);
        $misses    = (int) Cache::get('sentinel_metrics_cache_miss_count', 0);
        $fallbacks = (int) Cache::get('sentinel_metrics_fallback_count', 0);
        $threats   = (int) Cache::get('sentinel_metrics_threat_count', 0);
        $total     = $hits + $misses + $fallbacks;

        return [
            'total'     => $total,
            'hits'      => $hits,
            'misses'    => $misses,
            'fallbacks' => $fallbacks,
            'threats'   => $threats,
            'hit_rate'  => $total > 0 ? round(($hits / $total) * 100) . '%' : null,
        ];
    }
}
