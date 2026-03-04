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
        $middleware->alias([
            'tv' => \App\Http\Middleware\TvTokenMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'branch_manager' => \App\Http\Middleware\BranchManagerMiddleware::class,
            'super_admin' => \App\Http\Middleware\SuperAdminMiddleware::class,
                'admin_or_bm' => \App\Http\Middleware\AdminOrBranchManager::class,
                'auth.portal_capture' => \App\Http\Middleware\AuthenticatePortalCapture::class,
                'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '/internal/ai-chat-proxy',
            'portal-captures/ingest',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
