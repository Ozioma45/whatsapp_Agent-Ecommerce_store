<?php

namespace App\Providers;

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
        //
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
