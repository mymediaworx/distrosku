<?php

use App\Http\Middleware\BlockWhenSuspended;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware(['throttle:60,1', \App\Http\Middleware\VerifyDbaSignature::class])
                ->group(base_path('routes/dba.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SiteGround fronts PHP with an nginx proxy; trust it so HTTPS URLs are generated correctly.
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo('/admin/login');
        // Suspended-account check must run before auth so blocked users get a clear page, not a bare 403.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, BlockWhenSuspended::class);
        // 0.20.8: instance-manager suspend switch, global so it covers admin (Filament), portal, POS, driver, warehouse and Livewire.
        $middleware->append(\App\Http\Middleware\DbaSuspended::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Shared-hosting layout: ~/www/<site>/laravel (this app) + ~/www/<site>/public_html (web root).
if (is_dir($publicHtml = dirname(__DIR__, 2).'/public_html')) {
    $app->usePublicPath($publicHtml);
}

return $app;
