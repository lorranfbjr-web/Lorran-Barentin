<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Modules\NewsRadar\NewsRadarServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Endpoints JSON do Radar (Goal 3) são autenticados por chave (JrPanelKey),
        // não por sessão de browser — isentos de CSRF como os de api.php.
        $middleware->validateCsrfTokens(except: [
            'radar/assunto/*',
            'radar/verificar',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
