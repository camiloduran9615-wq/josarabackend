<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTokenCanMutate;
use App\Http\Middleware\EnsureTenantPlanLimits;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use App\Services\Asiento\AsientoOperacionInvalidaException;
use App\Services\Periodo\PeriodoOperacionInvalidaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // ⚠️  LOAD-BEARING — NO ELIMINAR (regresión BUG-003)
    // El array $listen de App\Providers\EventServiceProvider es la fuente única de verdad
    // para los listeners de dominio (Saldos → Audit → Cache, en ese orden).
    // Sin discover: false, Laravel registra los listeners DOS VECES (array $listen +
    // auto-discovery por typehint) y cuenta_saldos.movimiento_* queda con el doble del
    // valor real al aprobar asientos. Cubierto por:
    //   - tests/Unit/EventListenersNoDuplicatesTest.php (conteo + sufijo @handle)
    //   - tests/Feature/Saldos/CuentaSaldosNoSeDuplicaAlAprobarTest.php (end-to-end)
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // FIX C-2 (HANDOFF.md / QA_TEST_REPORT.md): alias para el middleware
        // que bloquea mutaciones de usuarios cuyo token no tiene abilities de
        // escritura (en la práctica, el rol readonly). Se aplica explícitamente
        // sobre el grupo de rutas protegidas en routes/tenant.php.
        $middleware->alias([
            'platform.admin' => EnsurePlatformAdmin::class,
            'token.can-mutate' => EnsureTokenCanMutate::class,
            'tenant.plan-limits' => EnsureTenantPlanLimits::class,
            'tenant.identifier' => InitializeTenancyByTenantIdentifier::class,
        ]);

        // FIX detectado al validar C-1: esta app es 100% API JSON, sin
        // ninguna ruta 'login' de tipo web. Por defecto, cuando
        // auth:sanctum rechaza una petición SIN el header
        // "Accept: application/json", el middleware Authenticate de Laravel
        // intenta redirigir a la ruta nombrada 'login' (comportamiento
        // pensado para apps con sesión web) — como esa ruta no existe, lanza
        // RouteNotFoundException y el cliente recibe un 500 en vez del 401
        // limpio esperado. Se fuerza a "nunca redirigir": cualquier petición
        // no autenticada siempre resuelve en AuthenticationException, que sí
        // está cubierta por el renderer JSON registrado abajo.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Respuesta uniforme JSend para todas las APIs.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validación fallida.',
                    'errors' => $e->errors(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'No autorizado.',
                ], Response::HTTP_FORBIDDEN);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autenticado.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->render(function (AsientoOperacionInvalidaException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], Response::HTTP_CONFLICT);
            }
        });

        $exceptions->render(function (PeriodoOperacionInvalidaException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], Response::HTTP_CONFLICT);
            }
        });

        // FIX C-7 / "C-3" del pedido de corrección (HANDOFF.md / QA_TEST_REPORT.md):
        // los 5 renderers de arriba solo cubren ValidationException,
        // AuthorizationException, AuthenticationException y las 2 excepciones
        // de dominio custom. Cualquier OTRA excepción (AccessDeniedHttpException
        // de un Policy denegado vía Symfony, NotFoundHttpException de una ruta
        // inexistente, HttpException de un abort() genérico, errores de BD,
        // etc.) caía al handler por defecto de Laravel, que con APP_DEBUG=true
        // devuelve el stack trace completo + rutas absolutas del servidor en
        // JSON cuando el cliente envía Accept: application/json. Confirmado en
        // vivo en 3 escenarios distintos (403 de Policy, 403 de abort(), 404 de
        // ruta inexistente) durante la auditoría de QA.
        //
        // Este catch-all captura CUALQUIER Throwable no manejado arriba y
        // siempre devuelve una respuesta JSON profesional sin trace, sin rutas
        // de servidor, sin SQL y sin nombres de clases/namespaces internos —
        // independientemente del valor de APP_DEBUG. Debe registrarse al
        // final: Laravel evalúa los renderers en orden y usa el primero que
        // matchee el tipo de excepción, así que los renderers específicos de
        // arriba siguen teniendo prioridad y esto solo actúa como red de
        // seguridad final.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            // Las excepciones HTTP (abort(403, '...'), abort(404), Policies
            // denegadas, etc.) tienen mensajes puestos a propósito por el
            // desarrollador para el cliente — son seguros de mostrar. Todo lo
            // demás (QueryException, TypeError, RuntimeException internas...)
            // puede contener SQL, rutas o nombres de clase: nunca se expone
            // su getMessage(), solo un mensaje genérico por status.
            $esHttpException = $e instanceof HttpExceptionInterface;
            $mensajeSeguro = $esHttpException ? trim((string) $e->getMessage()) : '';

            $message = $mensajeSeguro !== '' ? $mensajeSeguro : match (true) {
                $status === Response::HTTP_NOT_FOUND => 'Recurso no encontrado.',
                $status === Response::HTTP_FORBIDDEN => 'No autorizado.',
                $status === Response::HTTP_TOO_MANY_REQUESTS => 'Demasiadas solicitudes. Intenta de nuevo más tarde.',
                $status >= 500 => 'Ha ocurrido un error interno. Contacta a soporte si el problema persiste.',
                default => 'Error al procesar la solicitud.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        });
    })->create();
