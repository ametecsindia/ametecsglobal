<?php

// ONE SERVER, MANY APPS (20 Nov 2026): inside Ametecs Global the apps share one Apache/PHP process. Laravel's putenv() leaks
// each app's .env (DB_DATABASE...) into the others - ROVEXY was found on QRONEX's database. Every request reads its own .env.
// Same line as SmartEPT's (13 Aug 2026 incident).
\Illuminate\Support\Env::disablePutenv();

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class, \App\Http\Middleware\CompanyUrl::class, \App\Http\Middleware\AgCentral::class]);   // AgCentral: inside Ametecs Global
        $middleware->alias(['throttle' => \App\Http\Middleware\ThrottlePerRoute::class]);
        // ponytail: trust any proxy — qronex.app sits behind Cloudflare. env() here is empty once config is cached, so no env switch.
        // Upgrade: list Cloudflare's IP ranges instead of '*' if the origin is ever reachable directly.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
