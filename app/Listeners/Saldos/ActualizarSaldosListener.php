<?php

declare(strict_types=1);

namespace App\Listeners\Saldos;

use App\Events\Asiento\AsientoAprobado;
use App\Services\Saldos\SaldoUpserter;
use RuntimeException;

/**
 * Engancha a `AsientoAprobado` y acumula los deltas en `cuenta_saldos`.
 *
 * SÍNCRONO (no implementa ShouldQueue) — la actualización de saldos DEBE ocurrir
 * en la misma transacción que la aprobación del asiento. Si falla, todo se revierte.
 *
 * Idempotencia: el caller (`AsientoService::aprobar`) garantiza que un asiento no
 * se aprueba dos veces. Este listener confía en eso (no doble-checa).
 *
 * Concurrencia: el UPSERT (`ON CONFLICT`) en `SaldoUpserter` es atómico — Postgres
 * serializa naturalmente las actualizaciones sobre la misma key compuesta.
 */
final class ActualizarSaldosListener
{
    public function __construct(
        private readonly SaldoUpserter $upserter,
    ) {}

    /**
     * @throws RuntimeException si el asiento no tiene periodo_id asignado
     */
    public function handle(AsientoAprobado $event): void
    {
        $asiento = $event->asiento;

        if ($asiento->periodo_id === null) {
            throw new RuntimeException(
                "Asiento {$asiento->id} aprobado sin periodo_id — invariante violada."
            );
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant\AsientoLinea> $lineas */
        $lineas = $asiento->lineas()->get();

        $deltas = $this->upserter->agruparLineas(
            $lineas,
            (string) $asiento->periodo_id,
            $asiento->sucursal_id !== null ? (string) $asiento->sucursal_id : null,
        );

        foreach ($deltas as $delta) {
            $this->upserter->aplicar($delta, invertir: false);
        }
    }
}
