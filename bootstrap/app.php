<?php

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException as SpatieUnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {
        /*
        |--------------------------------------------------------------------------
        | API-Only Authentication Behaviour
        |--------------------------------------------------------------------------
        |
        | Laravel 12 configures unauthenticated guests to redirect to the named
        | "login" route by default. KHAM is an API-first application, so API
        | requests must never redirect to a web login page. Returning null here
        | lets the AuthenticationException be rendered as JSON below.
        |
        */
        $middleware->redirectGuestsTo(
            fn (Request $request) => null
        );

        /*
        |--------------------------------------------------------------------------
        | Custom Middleware Aliases
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |--------------------------------------------------------------------------
        | Force API Exceptions To JSON
        |--------------------------------------------------------------------------
        |
        | Browser requests may send Accept: text/html. For every /api/* request,
        | always render an API JSON response instead of Laravel's HTML error page.
        |
        */
        $exceptions->shouldRenderJsonWhen(
            function (Request $request, \Throwable $e): bool {
                return $request->is('api/*') || $request->expectsJson();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | 401 - Unauthenticated
        |--------------------------------------------------------------------------
        */
        $exceptions->render(
            function (AuthenticationException $e, Request $request) {
                if ($request->is('api/*')) {
                    return ApiResponse::error(
                        'Unauthenticated. Please provide a valid Bearer token.',
                        'AUTH_UNAUTHENTICATED',
                        401
                    );
                }

                return null;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | 403 - Role / Permission Forbidden
        |--------------------------------------------------------------------------
        */
        $exceptions->render(
            function (SpatieUnauthorizedException $e, Request $request) {
                if ($request->is('api/*')) {
                    return ApiResponse::error(
                        'You do not have permission to perform this action.',
                        'AUTH_FORBIDDEN',
                        403
                    );
                }

                return null;
            }
        );
    })

    ->create();