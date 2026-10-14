<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A local TLS proxy (tailscale serve) sits in front of `artisan serve`; trust its X-Forwarded-* headers
        // so generated URLs use https. Only loopback is trusted, so remote clients cannot spoof them.
        // The public demo sets TRUSTED_PROXIES to the subnet of the Docker network shared with its proxy.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
