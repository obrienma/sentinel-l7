<?php

namespace App\Http\Controllers;

use App\Models\EarlyAccessSignup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        return Inertia::render('Home', [
            'launchDate' => '2026-05-01',
            'appName' => 'Sentinel-L7'
        ]);
    }

    public function signup(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email:rfc,dns', 'unique:early_access_signups,email'],
        ], [
            'email.unique' => 'You\'re already on the list!',
        ]);

        EarlyAccessSignup::create($request->only('email'));

        return back()->with('success', 'You\'re on the list. We\'ll be in touch.');
    }
}
