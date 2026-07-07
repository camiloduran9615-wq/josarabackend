<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminPlanController;
use App\Http\Controllers\Api\Admin\AdminTenantController;
use App\Http\Controllers\Api\Admin\OperationsController;
use App\Http\Controllers\Api\Admin\PlatformAdminAuthController;
use App\Http\Controllers\Api\MunicipioDaneController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Dominio Central de la Plataforma
|--------------------------------------------------------------------------
| Estas rutas viven en el dominio central (no en los dominios de los tenants).
| Las rutas de recursos contables de cada empresa están en routes/tenant.php.
| El nombre de la plataforma se resuelve desde config/platform.php.
*/

// Ruta de salud para verificar que el API está operativa
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => config('platform.name').' API',
    'version' => 'v1',
]));

// Branding público de la plataforma (single source of truth: config/platform.php).
// Solo lectura, sin autenticación — el frontend lo consume al iniciar.
Route::get('/platform', PlatformController::class)->name('platform.config');

// ─── API v1 ───────────────────────────────────────────────────────────────────
Route::prefix('v1')->group(function () {
    // Super Admin JOSARA CLOUD — módulo central, separado de tenants.
    // Usa PlatformAdmin + Sanctum en la base central; no inicializa tenancy.
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('/auth/login', [PlatformAdminAuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('auth.login');

        Route::middleware(['auth:sanctum', 'platform.admin', 'throttle:120,1'])->group(function () {
            Route::get('/auth/me', [PlatformAdminAuthController::class, 'me'])->name('auth.me');
            Route::post('/auth/logout', [PlatformAdminAuthController::class, 'logout'])->name('auth.logout');
            Route::post('/admins', [PlatformAdminAuthController::class, 'store'])
                ->middleware('platform.admin:super_admin')
                ->name('admins.store');

            Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');

            Route::prefix('operations')->name('operations.')->group(function () {
                Route::get('/overview', [OperationsController::class, 'overview'])->name('overview');
                Route::get('/observability', [OperationsController::class, 'observability'])->name('observability');
                Route::get('/security', [OperationsController::class, 'security'])->name('security');
                Route::get('/support', [OperationsController::class, 'support'])->name('support');
                Route::get('/settings', [OperationsController::class, 'settings'])->name('settings');
                Route::put('/settings', [OperationsController::class, 'upsertSetting'])
                    ->middleware('platform.admin:super_admin')
                    ->name('settings.upsert');
            });

            Route::get('/tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
            Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');
            Route::put('/tenants/{tenant}', [AdminTenantController::class, 'update'])
                ->middleware('platform.admin:super_admin')
                ->name('tenants.update');
            Route::post('/tenants/{tenant}/change-plan', [AdminTenantController::class, 'changePlan'])
                ->middleware('platform.admin:super_admin')
                ->name('tenants.change-plan');
            Route::post('/tenants/{tenant}/suspend', [AdminTenantController::class, 'suspend'])
                ->middleware('platform.admin:super_admin')
                ->name('tenants.suspend');
            Route::post('/tenants/{tenant}/reactivate', [AdminTenantController::class, 'reactivate'])
                ->middleware('platform.admin:super_admin')
                ->name('tenants.reactivate');
            Route::get('/tenants/{tenant}/usage', [AdminTenantController::class, 'usage'])->name('tenants.usage');
            Route::get('/tenants/{tenant}/users', [AdminTenantController::class, 'users'])->name('tenants.users');
            Route::get('/tenants/{tenant}/billing', [AdminTenantController::class, 'billing'])->name('tenants.billing');

            Route::apiResource('plans', AdminPlanController::class)->except(['destroy']);
            Route::post('/plans/{plan}/duplicate', [AdminPlanController::class, 'duplicate'])
                ->middleware('platform.admin:super_admin')
                ->name('plans.duplicate');

            Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
        });
    });

    // Gestión de Empresas (Tenants) — FIX C-1 (HANDOFF.md / QA_TEST_REPORT.md):
    // index/show exponían razón social, NIT, email y UUID de TODOS los tenants
    // sin autenticación. Ahora requieren un token Sanctum central válido +
    // Gate 'manage-tenants' (ver AuthServiceProvider::boot()), que solo
    // autoriza a usuarios con role=admin. No existe hoy un flujo de login que
    // emita tokens contra la conexión central (todo login pasa primero por
    // tenancy()->initialize()), así que este endpoint queda cerrado por
    // defecto hasta que se implemente un login de plataforma — preferible a
    // dejarlo abierto. Documentado como riesgo/seguimiento en FIX_REPORT.md.
    //
    // El endpoint store (registro público) está rate-limited para prevenir abuso:
    // crear un tenant provisiona una BD física + corre migraciones + seeders, lo
    // que es costoso y abusable como vector de DoS si no se acota.
    Route::apiResource('tenants', TenantController::class)
        ->only(['index', 'show'])
        ->middleware('auth:sanctum');

    Route::post('/tenants', [TenantController::class, 'store'])
        ->middleware('throttle:5,60') // 5 registros por hora por IP
        ->name('tenants.store');

    // Auth — Login es central (identifica el tenant y genera el token en su DB).
    // Logout y me viven en las rutas del tenant (token está en la DB del tenant).
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1') // Máx 5 intentos por minuto por IP
            ->name('auth.login');
    });

    // ── Catálogo público DANE (compartido por todos los tenants) ────────────
    // Catálogo de municipios oficiales (DIVIPOLA). Sin autenticación porque
    // es información pública y se necesita en formularios de registro.
    Route::get('/municipios', [MunicipioDaneController::class, 'index'])->name('municipios.index');
    Route::get('/municipios/{codigo}', [MunicipioDaneController::class, 'show'])->name('municipios.show');
});
