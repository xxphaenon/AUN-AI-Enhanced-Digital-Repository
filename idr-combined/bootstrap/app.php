<?php

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // This is an API: never redirect guests to a login page; answer 401.
        $middleware->redirectGuestsTo(fn () => null);

        // The Next.js frontend proxies API calls from the same machine
        // (app/api/backend). Trust the client IP it forwards, so rate limits
        // (e.g. 5 logins a minute) apply per real user, not to everyone at once.
        // Only these addresses are trusted; set TRUSTED_PROXIES if Next.js runs elsewhere.
        $middleware->trustProxies(
            at: array_filter(explode(',', env('TRUSTED_PROXIES', '127.0.0.1,::1'))),
            headers: Request::HEADER_X_FORWARDED_FOR,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Every API error uses the same envelope as the contract's success
        // payloads: { "status": "error", "message": "...", ... }
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $message = $e->getPrevious() instanceof ModelNotFoundException
                ? 'Resource not found.'
                : ($e->getMessage() ?: 'Not found.');

            return response()->json(['status' => 'error', 'message' => $message], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Request failed.',
            ], $e->getStatusCode(), $e->getHeaders());
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request) || config('app.debug')) {
                return null; // fall through to Laravel's detailed debug output
            }

            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred.',
            ], 500);
        });
    })->create();
