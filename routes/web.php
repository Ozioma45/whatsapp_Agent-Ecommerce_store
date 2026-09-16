<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicStoreController;
use App\Http\Controllers\Settings\StoreSettingsController;
use Illuminate\Support\Facades\Route;

// Main domain: the platform itself (landing page, auth, dashboard, settings).
Route::domain(config('app.domain'))->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });

    Route::middleware('guest')->group(function () {
        Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('register', [RegisteredUserController::class, 'store']);

        Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [AuthenticatedSessionController::class, 'store']);
    });

    Route::middleware('auth')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('settings', [StoreSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [StoreSettingsController::class, 'update'])->name('settings.update');

        Route::resource('categories', CategoryController::class)->except('show');
        Route::resource('products', ProductController::class)->except('show');

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

// Business subdomains: the public store, resolved by handle. {business}.{app.domain}
Route::domain('{business}.'.config('app.domain'))->group(function () {
    Route::get('/', [PublicStoreController::class, 'show'])->name('store.show');

    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart/{product}', [CartController::class, 'store'])->name('cart.store');
    Route::patch('cart/{product}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('cart/{product}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
});
