<?php

namespace App\Providers;

use App\Support\Ai\AiProviderInterface;
use App\Support\Ai\NullAiProvider;
use App\Support\Cart;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // No real AI provider exists yet; a concrete one replaces this
        // binding in a later phase.
        $this->app->bind(AiProviderInterface::class, NullAiProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every page built on the storefront layout shows a cart count in
        // its header. A page that already computed its own cart (the cart
        // page itself) passes "cartCount" explicitly and this is skipped.
        View::composer('layouts.storefront', function (ViewInstance $view): void {
            $data = $view->getData();

            if (array_key_exists('cartCount', $data)) {
                return;
            }

            $business = $data['business'] ?? null;

            if ($business) {
                $view->with('cartCount', (new Cart($business))->count());
            }
        });
    }
}
