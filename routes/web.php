<?php

use App\Http\Controllers\Admin\BusinessController as AdminBusinessController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FeatureController as AdminFeatureController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicStoreController;
use App\Http\Controllers\Settings\AiAssistantSettingsController;
use App\Http\Controllers\Settings\PaymentController;
use App\Http\Controllers\Settings\StoreSettingsController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Main domain: the platform itself (landing page, auth, dashboard, settings).
Route::domain(config('app.domain'))->group(function () {
    // The WhatsApp webhook is a single, platform-wide endpoint (Meta allows
    // one callback URL per app). It deliberately sits outside both the
    // "maintenance" and "auth" groups: Meta must always be able to reach
    // it, and it authenticates itself via request signature, not a session.
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp.handle');

    // Same reasoning as the WhatsApp webhook above: Paystack calls this
    // directly with no session, authenticating itself via signature.
    Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle'])->name('webhooks.paystack.handle');

    Route::middleware('maintenance')->group(function () {
        Route::get('/', function () {
            return view('welcome');
        });
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

        Route::get('subscription', [SubscriptionController::class, 'edit'])->name('subscription.edit');
        Route::post('subscription/request', [SubscriptionController::class, 'requestChange'])->name('subscription.request');
        Route::post('subscription/pay', [PaymentController::class, 'initiate'])->name('subscription.payment.initiate');
        Route::get('subscription/callback', [PaymentController::class, 'callback'])->name('subscription.payment.callback');

        Route::get('ai-assistant', [AiAssistantSettingsController::class, 'edit'])->name('ai.edit');
        Route::put('ai-assistant', [AiAssistantSettingsController::class, 'update'])->name('ai.update');
        Route::post('ai-assistant/simulate', [AiAssistantSettingsController::class, 'simulate'])->name('ai.simulate');
        Route::post('ai-assistant/simulate/reset', [AiAssistantSettingsController::class, 'resetSimulation'])->name('ai.simulate.reset');

        Route::resource('categories', CategoryController::class)->except('show');
        Route::resource('products', ProductController::class)->except('show');
        Route::resource('orders', OrderController::class)->only(['index', 'show', 'update']);

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });

    // Platform admin: separate from the business-owner dashboard above,
    // restricted to admins only, and always on the main domain (never a
    // tenant subdomain — this group lives inside the same domain() as
    // everything above, not inside the {business} subdomain group below).
    Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::get('businesses', [AdminBusinessController::class, 'index'])->name('businesses.index');
        Route::get('businesses/{business}', [AdminBusinessController::class, 'show'])->name('businesses.show');
        Route::patch('businesses/{business}/plan', [AdminBusinessController::class, 'updatePlan'])->name('businesses.updatePlan');
        Route::patch('businesses/{business}/subscription/suspend', [AdminBusinessController::class, 'suspendSubscription'])->name('businesses.subscription.suspend');
        Route::patch('businesses/{business}/subscription/reactivate', [AdminBusinessController::class, 'reactivateSubscription'])->name('businesses.subscription.reactivate');

        Route::get('subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::patch('subscriptions/{subscription}/approve', [AdminSubscriptionController::class, 'approve'])->name('subscriptions.approve');
        Route::patch('subscriptions/{subscription}/reject', [AdminSubscriptionController::class, 'reject'])->name('subscriptions.reject');

        Route::get('payments', [AdminPaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');

        Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
        Route::get('plans/{plan}', [AdminPlanController::class, 'show'])->name('plans.show');
        Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');
        Route::delete('plans/{plan}', [AdminPlanController::class, 'destroy'])->name('plans.destroy');

        Route::get('features', [AdminFeatureController::class, 'index'])->name('features.index');
        Route::patch('features/{feature}', [AdminFeatureController::class, 'update'])->name('features.update');

        Route::get('settings', [AdminSettingController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [AdminSettingController::class, 'update'])->name('settings.update');

        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.updateRole');
    });
});

// Business subdomains: the public store, resolved by handle. {business}.{app.domain}
Route::domain('{business}.'.config('app.domain'))->middleware('maintenance')->group(function () {
    Route::get('/', [PublicStoreController::class, 'show'])->name('store.show');

    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart/{product}', [CartController::class, 'store'])->name('cart.store');
    Route::patch('cart/{product}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('cart/{product}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

    Route::post('checkout', [CartController::class, 'checkout'])->name('cart.checkout');
});
