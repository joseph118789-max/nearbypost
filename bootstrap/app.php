<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\LogApiTiming;
use App\Http\Middleware\ApiAnalyticsMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ApiAnalyticsMiddleware::class,
            LogApiTiming::class,
        ]);
        $middleware->trustProxies('*');

        // Must run before anything asks who the visitor is. traefik overwrites
        // X-Forwarded-For with the Cloudflare edge it is talking to, so without
        // this every reader looks like a Cloudflare data centre - and the rate
        // limiter buckets thousands of unrelated people together.
        $middleware->web(prepend: [
            \App\Http\Middleware\TrustCloudflare::class,
        ]);

        // Where an unauthenticated request is sent. There are two logins on
        // this site and one global default cannot serve both: sending a guest
        // who asked for /admin to the public chooser, whose admin door leads
        // back to /admin, is a loop.
        $middleware->redirectGuestsTo(function ($request) {
            return $request->is('admin', 'admin/*')
                ? route('admin.login')
                : route('login');
        });

        // And where an already-authenticated one is sent when it asks for a
        // login form. The default is the site root, so an administrator who was
        // already signed in and clicked through to the panel landed on the
        // public homepage - which reads exactly like a login that does not
        // work.
        $middleware->redirectUsersTo(function ($request) {
            return $request->is('admin', 'admin/*')
                ? route('admin.dashboard')
                : route('contribute.index');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
