<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
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
            'metrics' => $this->metrics(),
        ]);
    }

    private function metrics(): array
    {
        $hits      = (int) Cache::get('sentinel_metrics_cache_hit_count', 0);
        $misses    = (int) Cache::get('sentinel_metrics_cache_miss_count', 0);
        $fallbacks = (int) Cache::get('sentinel_metrics_fallback_count', 0);
        $total     = $hits + $misses + $fallbacks;

        return [
            'total'     => $total,
            'hits'      => $hits,
            'misses'    => $misses,
            'fallbacks' => $fallbacks,
            'hit_rate'  => $total > 0 ? round(($hits / $total) * 100) . '%' : null,
        ];
    }
}
