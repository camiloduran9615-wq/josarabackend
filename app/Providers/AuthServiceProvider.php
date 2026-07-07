<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\Impuesto;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use App\Policies\AsientoPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ImpuestoPolicy;
use App\Policies\PeriodoPolicy;
use App\Policies\ReportePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Mapeo modelo → policy.
     *
     * NOTA: `ReportePolicy::class => ReportePolicy::class` parece un binding
     * inválido a primera vista (mapea la policy a sí misma en vez de a un
     * modelo Eloquent), y así se documentó inicialmente en HANDOFF.md. Es en
     * realidad el patrón idiomático de Laravel para autorizar sin modelo:
     * `App\Http\Requests\CierreAnual\EjecutarCierreAnualRequest::authorize()`
     * llama `$this->user()?->can('ejecutarCierreAnual', ReportePolicy::class)`
     * — al eliminar este binding durante el fix de C-1/C-2, el cierre anual
     * empezó a fallar con 403 (`PeriodoFeatureTest::
     * test_close_periodo_anual_genera_asientos_de_cierre`), confirmando en
     * vivo que SÍ estaba en uso. Se mantiene tal como estaba.
     */
    protected $policies = [
        Asiento::class => AsientoPolicy::class,
        PeriodoContable::class => PeriodoPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        Impuesto::class => ImpuestoPolicy::class,
        ReportePolicy::class => ReportePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // FIX C-1 (HANDOFF.md / QA_TEST_REPORT.md): solo usuarios con
        // role=admin pueden listar/ver el directorio de tenants. Se usa un
        // Gate (no una Policy) porque Tenant no es el recurso "propio" del
        // usuario autenticado — es un listado administrativo transversal.
        Gate::define('manage-tenants', fn (User $user): bool => $user->role === User::ROLE_ADMIN);
    }
}
