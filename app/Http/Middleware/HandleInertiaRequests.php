<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],
            'features' => [
                'env_badge'         => config('features.env_badge'),
                'dashboard_access'  => config('features.dashboard_access'),
                'app_env'           => app()->environment(),
            ],
        ]);
    }
}
