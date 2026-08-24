<?php

use App\Connector\Contract;
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
        // The connector talks machine JSON, not a browser form: an empty
        // `theme.version` is a documented value and a reported string is the
        // client site's, character for character. Both mungings are off there.
        $connector = fn (Request $request): bool => $request->is(Contract::requestPattern());

        $middleware->convertEmptyStringsToNull(except: [$connector]);
        $middleware->trimStrings(except: [$connector]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
