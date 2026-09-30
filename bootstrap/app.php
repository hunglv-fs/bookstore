<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
        ]);

        // This is an API: unauthenticated requests get a 401 JSON response, never
        // a redirect to a (non-existent) login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always get JSON, even without an "Accept: application/json" header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Client errors (4xx) from the API are always a plain {"message"} body,
        // even with APP_DEBUG on: a stack trace never helps the client.
        // Every 404 has the same body: missing route-model bindings would
        // otherwise leak model class names ("No query results for model
        // [App\Models\Order] 5"), and policies that hide other customers'
        // records (denyAsNotFound) must look exactly like a missing record.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            if (! $request->is('api/*') || $status >= 500) {
                return null;
            }

            $message = $status === 404
                ? 'Resource not found.'
                : ($e->getMessage() ?: Response::$statusTexts[$status] ?? 'Error');

            return response()->json(['message' => $message], $status, $e->getHeaders());
        });
    })->create();
