<?php

declare(strict_types=1);

namespace App\Listeners\Saldos;

use App\Events\Asiento\AsientoAnulado;
use App\Services\Saldos\SaldoUpserter;
use RuntimeException;

/**
 * Engancha a `AsientoAnulado`: resta los movimientos que el asiento original
 * aportó a `cuenta_saldos`.
 *
 * Reglas (Contador §2.4):
 *  - Anular solo es válido en periodo abierto (validado por `PeriodoPolicy::anularAsiento`).
 *  - En periodo cerrado se usa reverso (que crea un asiento espejo nuevo, manejado
 *    por `ActualizarSaldosListener` — NO por este listener).
 *
 * SÍNCRONO: la resta debe completarse en la misma transacción que la anulación.
 *
 * Detección de corrupción: si tras restar quedan saldos negativos, `SaldoUpserter`
 * lanza RuntimeException → la anulación se revierte y se emite alerta.
 */
final class ReversarSaldosListener
{
    public function __construct(
        private readonly SaldoUpserter $upserter,
    ) {}

    /**
     * @throws RuntimeException si el asiento no tiene periodo_id o si la resta produce saldos negativos
     */
    public function handle(AsientoAnulado $event): void
    {
        $asiento = $event->asiento;

        if ($asiento->periodo_id === null) {
            throw new RuntimeException(
                "Asiento {$asiento->id} anulado sin periodo_id — invariante violada."
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
            $this->upserter->aplicar($delta, invertir: true);
        }
    }
}
