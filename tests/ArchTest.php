<?php

uses(Tests\TestCase::class);

// ─── Controllers ──────────────────────────────────────────────────────────────

arch('controllers extend the base Controller')
    ->expect('App\Http\Controllers')
    ->toExtend('App\Http\Controllers\Controller');

arch('controllers do not use Http facade directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\Http');

// ─── Models ───────────────────────────────────────────────────────────────────

arch('models do not make outbound HTTP calls')
    ->expect('App\Models')
    ->not->toUse('Illuminate\Support\Facades\Http');

arch('models do not use Redis directly')
    ->expect('App\Models')
    ->not->toUse('Illuminate\Support\Facades\Redis');

// ─── Services ─────────────────────────────────────────────────────────────────

arch('services do not depend on controllers')
    ->expect('App\Services')
    ->not->toUse('App\Http\Controllers');

arch('services do not render Inertia responses')
    ->expect('App\Services')
    ->not->toUse('Inertia\Inertia');

// ─── Compliance drivers ───────────────────────────────────────────────────────

arch('compliance drivers implement ComplianceDriver contract')
    ->expect('App\Services\Compliance')
    ->toImplement('App\Contracts\ComplianceDriver');

arch('compliance drivers do not depend on controllers')
    ->expect('App\Services\Compliance')
    ->not->toUse('App\Http\Controllers');

// ─── Domain Logic Isolation ───────────────────────────────────────────────────

arch('sentinel logic does not use Http facade')
    ->expect('App\Services\Sentinel\Logic')
    ->not->toUse('Illuminate\Support\Facades\Http');

arch('sentinel logic does not use Redis facade')
    ->expect('App\Services\Sentinel\Logic')
    ->not->toUse('Illuminate\Support\Facades\Redis');

// ─── Global ───────────────────────────────────────────────────────────────────

arch('no code uses dd or dump')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);
