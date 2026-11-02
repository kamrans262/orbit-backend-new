<?php

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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stable machine-readable error contract for M14 owner APIs only.
        // Do not alter other modules' established response/error semantics.
        $exceptions->render(function (\Throwable $error, Request $request) {
            if (!$request->is('api/v1/me/notifications*')) return null;
            $status = match (true) {
                $error instanceof \Illuminate\Validation\ValidationException => 422,
                $error instanceof \Illuminate\Auth\AuthenticationException => 401,
                $error instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface =>
                    $error->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                400 => 'invalid_request',
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                422 => 'validation_error',
                429 => 'rate_limited',
                503 => 'service_unavailable',
                default => 'server_error',
            };
            $message = match ($status) {
                401 => 'Authentication required.',
                403 => 'Notification access denied.',
                404 => 'Notification resource unavailable.',
                409 => 'Notification state changed. Refresh and retry.',
                422 => 'Check the notification request and retry.',
                429 => 'Too many requests. Retry later.',
                503 => 'Notifications are temporarily unavailable.',
                default => 'Request could not be completed.',
            };
            return response()->json([
                'message' => $message,
                'error' => ['code' => $code, 'message' => $message],
            ], $status)->header('Cache-Control', 'private, no-store');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
