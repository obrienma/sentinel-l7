<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Guard: only available in non-production environments for now
        if (!config('features.dashboard_access')) {
            abort(404);
        }

        return Inertia::render('Dashboard', [
            'appName' => 'Sentinel-L7',
        ]);
    }
}
