<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        return Inertia::render('Home', [
            'launchDate' => '2026-05-01',
            'appName' => 'Sentinel-L7'
        ]);
    }
}
