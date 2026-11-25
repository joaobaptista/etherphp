<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureRootAdmin;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\SecurityHeaders;
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
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'jwt' => JwtAuthenticate::class,
            'admin' => EnsureAdmin::class,
            'root' => EnsureRootAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $firstMessage = collect($e->errors())->flatten()->first();

                return response()->json([
                    'detail' => is_string($firstMessage) ? $firstMessage : 'Dados inválidos.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'detail' => $e->getMessage() !== '' ? $e->getMessage() : 'Erro na requisição.',
                ], $e->getStatusCode());
            }

            return null;
        });
    })->create();
