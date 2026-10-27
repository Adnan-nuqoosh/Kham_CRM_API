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
        //
    }

    public function boot(): void
    {
        // Existing CRM login rate limiter
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Storefront public and authenticated API rate limiter
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            $identifier = $user
                ? get_class($user) . ':' . $user->getAuthIdentifier()
                : $request->ip();

            return Limit::perMinute(60)->by($identifier);
        });
    }
}
