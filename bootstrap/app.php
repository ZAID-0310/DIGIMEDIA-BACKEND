<?php

use App\Http\Middleware\isAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RegisterUser;
use App\Http\Middleware\ValidateApiKey;
use App\Http\Middleware\SecurityHeadersMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'role' => CheckRole::class,
            'permission' => CheckPermission::class,
            'actions' => RegisterUser::class,
            'api.key' => ValidateApiKey::class
        ]);
        $middleware->statefulApi();
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->append(RegisterUser::class);
        $middleware->append(SecurityHeadersMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Forzar respuesta JSON en rutas de API y documentación
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->is('docs*') || $request->expectsJson();
        });

        // Sanitizar mensajes para evitar exposición de rutas físicas o datos de BD (CWE-209 / CWE-497)
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Dejar pasar errores de validación (422) y autenticación (401) estándar de Laravel
            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }

            if ($request->is('api/*') || $request->is('docs*') || $request->expectsJson()) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

                // En staging/producción (APP_DEBUG=false) nunca devolver el mensaje crudo ni rutas internas
                if (!config('app.debug')) {
                    $safeMessages = [
                        403 => 'No tienes permisos para realizar esta acción.',
                        404 => 'Recurso no encontrado.',
                        405 => 'Método HTTP no permitido.',
                        429 => 'Demasiadas solicitudes. Intenta más tarde.',
                    ];

                    return response()->json([
                        'success' => false,
                        'message' => $safeMessages[$status] ?? 'Error interno del servidor.',
                    ], $status);
                }
            }
        });
    })->create();