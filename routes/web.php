<?php
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/signup', [HomeController::class, 'signup'])->name('signup');

// Dashboard — feature-flagged; DashboardController enforces 404 on production
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');