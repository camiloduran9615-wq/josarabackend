<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Repositories\Central\TarifaIcaRepository;
use App\Repositories\Central\UvtAnualRepository;
use App\Repositories\Contracts\TarifaIcaRepositoryInterface;
use App\Repositories\Contracts\UvtAnualRepositoryInterface;
use App\Services\Impuesto\ImpuestoCalculadorService;
use App\Services\Inventario\CostoPromedioService;
use App\Services\Periodo\CierreAnualService;
use App\Services\Saldos\RecalcularPeriodoService;
use App\Services\Inventario\InventarioCuentaResolver;
use App\Services\Inventario\KardexService;
use App\Services\LibroMayor\LibroMayorService;
use App\Services\Reportes\BalanceComprobacionService;
use App\Services\Reportes\BalanceGeneralService;
use App\Services\Reportes\CacheReportesService;
use App\Services\Reportes\EstadoResultadosService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CostoPromedioService::class);
        $this->app->singleton(KardexService::class);
        $this->app->singleton(InventarioCuentaResolver::class);

        // EPIC-LMB-001 — Repositorios centrales (catálogos compartidos, alto hit-rate)
        $this->app->singleton(UvtAnualRepositoryInterface::class, UvtAnualRepository::class);
        $this->app->singleton(TarifaIcaRepositoryInterface::class, TarifaIcaRepository::class);

        // EPIC-LMB-001 — Servicios tributarios + cierre anual
        $this->app->singleton(ImpuestoCalculadorService::class);
        $this->app->singleton(CierreAnualService::class);
        $this->app->singleton(RecalcularPeriodoService::class);

        // EPIC-LMB-001 — Servicios de reportes (singletons por request para cache warm)
        $this->app->singleton(CacheReportesService::class);
        $this->app->singleton(BalanceGeneralService::class);
        $this->app->singleton(EstadoResultadosService::class);
        $this->app->singleton(BalanceComprobacionService::class);
        $this->app->singleton(LibroMayorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('tenant-registration', static function (Request $request): array {
            $ip = $request->ip();

            return [
                // Evita ráfagas automatizadas incluso cuando el payload es inválido.
                Limit::perMinute(20)->by('tenant-registration-attempts:'.$ip),

                // El cupo costoso solo se consume cuando la petición supera la
                // validación. Un 422 debe permitir que el usuario corrija datos
                // sin quedar bloqueado durante una hora.
                Limit::perHour(5)
                    ->by('tenant-registration-provisioning:'.$ip)
                    ->after(static fn ($response): bool => $response->getStatusCode() !== 422),
            ];
        });

        $allowedOrigins = array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', 'https://josara.colombiaapp.fun'))),
        )));

        config([
            'cors.paths' => ['api/*', 'sanctum/csrf-cookie'],
            'cors.allowed_methods' => ['*'],
            'cors.allowed_origins' => $allowedOrigins,
            'cors.allowed_origins_patterns' => [],
            'cors.allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'Accept'],
            'cors.exposed_headers' => [],
            'cors.max_age' => 0,
            'cors.supports_credentials' => false,
        ]);

        /**
         * Usar el PersonalAccessToken del tenant activo.
         * Esto garantiza que los tokens de Sanctum se validen contra la DB
         * del tenant correcto — aislamiento cross-tenant a nivel de token.
         *
         * Sanctum buscará el token en la tabla personal_access_tokens
         * de la DB del tenant (no en la central).
         */
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
    }
}
