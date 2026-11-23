<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*')
            ? null
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'message' => 'Unauthenticated.',
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                    ],
                ], 401);
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'message' => 'Validation failed.',
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'errors' => $exception->errors(),
                    ],
                ], 422);
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            $code = $status === 422 ? 'VALIDATION_ERROR' : 'INTERNAL_ERROR';

            return response()->json([
                'data' => null,
                'meta' => null,
                'message' => $exception->getMessage() ?: 'An error occurred.',
                'error' => [
                    'code' => $code,
                ],
            ], $status);
        });
    })->create();
