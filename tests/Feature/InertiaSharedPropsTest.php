<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// ─── Flash messages ───────────────────────────────────────────────────────────
// HandleInertiaRequests shares flash.success and flash.error on every response.
// We verify the keys exist and carry the right values.

it('shares a flash success message via inertia props', function () {
    $user = User::factory()->create();

    // Flash a message then hit any Inertia route to see the shared props
    $this->actingAs($user)
        ->withSession(['success' => 'Saved!'])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('flash.success', 'Saved!'));
});

it('shares a flash error message via inertia props', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['error' => 'Something went wrong.'])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('flash.error', 'Something went wrong.'));
});

it('shares null flash values when no flash is set', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null)
        );
});

// ─── Feature flags ────────────────────────────────────────────────────────────
// config/features.php flags are shared under the `features` key on every page.

it('shares feature flags via inertia props', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->has('features.env_badge')
            ->has('features.dashboard_access')
            ->has('features.app_env')
        );
});

it('reflects the env_badge flag value from config', function () {
    config(['features.env_badge' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('features.env_badge', true));

    config(['features.env_badge' => false]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('features.env_badge', false));
});

it('shares the current app environment in features.app_env', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('features.app_env', app()->environment()));
});
