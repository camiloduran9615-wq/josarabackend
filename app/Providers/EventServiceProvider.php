<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Asiento\AsientoAnulado;
use App\Events\Asiento\AsientoAprobado;
use App\Events\Asiento\AsientoReversado;
use App\Events\CierreAnual\CierreAnualEjecutado;
use App\Events\Periodo\PeriodoBloqueadoFiscal;
use App\Events\Periodo\PeriodoCerrado;
use App\Events\Periodo\PeriodoReabierto;
use App\Events\Saldos\SaldosInconsistenciaDetectada;
use App\Listeners\Audit\RecordAuditOnAsientoAnulado;
use App\Listeners\Audit\RecordAuditOnAsientoAprobado;
use App\Listeners\Audit\RecordAuditOnAsientoReversado;
use App\Listeners\Audit\RecordAuditOnPeriodoBloqueadoFiscal;
use App\Listeners\Audit\RecordAuditOnPeriodoCerrado;
use App\Listeners\Audit\RecordAuditOnPeriodoReabierto;
use App\Listeners\Cache\InvalidarCacheReportesListener;
use App\Listeners\Saldos\ActualizarSaldosListener;
use App\Listeners\Saldos\ReversarSaldosListener;
use App\Listeners\Saldos\SnapshotSaldosListener;
use App\Listeners\Webhooks\NotificarN8nListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Mapa de eventos de dominio → listeners.
     *
     * Orden importa solo para listeners SÍNCRONOS:
     *  1. Saldos (integridad contable, debe completar antes de auditar)
     *  2. Audit  (registra el resultado final)
     *  3. Cache  (invalida tras commit lógico)
     *  4. Webhook N8n (asíncrono, no bloquea)
     */
    protected $listen = [
        // ── Asientos ────────────────────────────────────────────────────
        AsientoAprobado::class => [
            ActualizarSaldosListener::class,        // EPIC-LMB-001: actualiza cuenta_saldos
            RecordAuditOnAsientoAprobado::class,    // EPIC-002: AuditLog hash chain
            InvalidarCacheReportesListener::class,  // EPIC-LMB-001: purge cache Redis
        ],
        AsientoAnulado::class => [
            ReversarSaldosListener::class,
            RecordAuditOnAsientoAnulado::class,
            InvalidarCacheReportesListener::class,
        ],
        AsientoReversado::class => [
            // El reverso GENERA un asiento nuevo (espejo D↔C). Ese nuevo asiento
            // dispara su propio AsientoAprobado, que es procesado por ActualizarSaldosListener.
            // Aquí solo auditamos el evento de reverso y purgamos cache.
            RecordAuditOnAsientoReversado::class,
            InvalidarCacheReportesListener::class,
        ],

        // ── Periodos ────────────────────────────────────────────────────
        PeriodoCerrado::class => [
            SnapshotSaldosListener::class,          // EPIC-LMB-001: snapshot inmutable
            RecordAuditOnPeriodoCerrado::class,
            InvalidarCacheReportesListener::class,
            NotificarN8nListener::class,
        ],
        PeriodoReabierto::class => [
            RecordAuditOnPeriodoReabierto::class,
            InvalidarCacheReportesListener::class,
            NotificarN8nListener::class,
        ],
        PeriodoBloqueadoFiscal::class => [
            RecordAuditOnPeriodoBloqueadoFiscal::class,
        ],

        // ── Cierre anual ────────────────────────────────────────────────
        CierreAnualEjecutado::class => [
            InvalidarCacheReportesListener::class,
            NotificarN8nListener::class,
            // Auditoría se registra inline en CierreAnualService (no via listener)
            // porque el evento ya implica los asientos auditados individualmente.
        ],

        // ── Saldos ──────────────────────────────────────────────────────
        SaldosInconsistenciaDetectada::class => [
            NotificarN8nListener::class,
            // RecordAuditOnSaldosInconsistencia se agregará cuando esté implementado
            // (programado para D5 junto con ReconciliarSaldosJob).
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
