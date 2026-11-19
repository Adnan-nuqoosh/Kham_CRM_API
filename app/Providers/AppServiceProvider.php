<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Local/testing: higher ceilings so catalog + settings polling does not 429.
        // Production keeps tighter shared buckets.
        $local = app()->environment(['local', 'testing']);

        RateLimiter::for('login', function (Request $request) use ($local) {
            return Limit::perMinute($local ? 60 : 10)->by($request->ip());
        });

        // Shared by most public /storefront/* GETs (markets, products, quote, etc.).
        RateLimiter::for('storefront-quote', function (Request $request) use ($local) {
            return Limit::perMinute($local ? 300 : 20)->by($request->ip());
        });

        RateLimiter::for('storefront-test-order', function (Request $request) use ($local) {
            return Limit::perMinute($local ? 60 : 5)->by($request->ip());
        });
    }
}
