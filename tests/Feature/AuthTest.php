<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Redis::shouldReceive('executeRaw')->andReturn([]);
});

// ─── Login page ───────────────────────────────────────────────────────────────

it('shows the login page to guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Login'));
});

it('redirects authenticated users away from the login page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect('/dashboard');
});

// ─── Login submission ─────────────────────────────────────────────────────────

it('logs in a user with valid credentials and redirects to dashboard', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email'    => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('returns an error for invalid credentials', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $this->post('/login', [
        'email'    => 'test@example.com',
        'password' => 'wrong-password',
    ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('validates that email is required', function () {
    $this->post('/login', ['password' => 'password'])
        ->assertSessionHasErrors('email');
});

it('validates that password is required', function () {
    $this->post('/login', ['email' => 'test@example.com'])
        ->assertSessionHasErrors('password');
});

it('validates that email is a valid email address', function () {
    $this->post('/login', ['email' => 'not-an-email', 'password' => 'password'])
        ->assertSessionHasErrors('email');
});

it('redirects to intended url after login', function () {
    $user = User::factory()->create();

    // Visit a protected page first — Laravel stores the intended URL in session
    $this->get('/dashboard');

    $this->post('/login', [
        'email'    => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect('/dashboard');
});

// ─── Logout ───────────────────────────────────────────────────────────────────

it('logs out an authenticated user and redirects home', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

it('prevents guests from hitting the logout route', function () {
    $this->post('/logout')
        ->assertRedirect(route('login'));
});

// ─── Dashboard middleware ─────────────────────────────────────────────────────

it('redirects guests away from the dashboard', function () {
    $this->get('/dashboard')
        ->assertRedirect(route('login'));
});

it('lets authenticated users access the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard'));
});

it('passes user name and email as props to the dashboard', function () {
    $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('user.name', 'Jane Doe')
            ->where('user.email', 'jane@example.com')
        );
});
