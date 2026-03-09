<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // Props passed here become the React component's function arguments.
        // As we build out the dashboard, real data from Redis/DB will flow through here.
        // TODO: scope all data queries by auth()->user()->tenant_id when multitenancy lands
        return Inertia::render('Dashboard', [
            'user' => [
                'name'  => auth()->user()->name,
                'email' => auth()->user()->email,
            ],
        ]);
    }
}
