<?php

declare(strict_types=1);

namespace App\Services\Periodo;

use App\Events\Periodo\PeriodoCerrado;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// CierreAnualService se inyecta pero no lo importamos — se resuelve via app() para
// evitar dependencia circular en el container (CerrarPeriodoService tiene inyección
// liviana; el cierre anual es un camino poco frecuente).

/**
 * Cierra un periodo contable (mensual o anual).
 * Para cierre anual genera asientos de cancelación de cuentas de resultado
 * y traslado a utilidad/pérdida del ejercicio.
 *
 * Idempotente: cerrar dos veces retorna excepción 409 (no duplica asientos).
 */
class CerrarPeriodoService
{
    public function ejecutar(PeriodoContable $periodo, User $contador, ?string $motivo = null): PeriodoContable
    {
        if (! $periodo->estaAbierto()) {
            throw new PeriodoOperacionInvalidaException(
                "El periodo {$periodo->codigo} no está abierto (estado={$periodo->estado})."
            );
        }

        $checklist = $this->ejecutarChecklist($periodo);
        $bloqueos = collect($checklist)->filter(
            fn (array $item): bool => $item['ok'] === false
        );
        if ($bloqueos->isNotEmpty()) {
            throw new PreCierreFallidoException(
                'No se puede cerrar el periodo: hay validaciones pendientes.',
                $checklist,
            );
        }

        return DB::transaction(function () use ($periodo, $contador, $motivo): PeriodoContable {
            $periodo->refresh();
            if (! $periodo->estaAbierto()) {
                throw new PeriodoOperacionInvalidaException('Otro proceso cerró el periodo.');
            }

            // Para cierre anual delegamos a CierreAnualService (Día 8).
            // CerrarPeriodoService solo cierra el periodo contenedor;
            // los asientos 5905→3606 los genera CierreAnualService en un paso separado
            // (endpoint POST /cierre-anual/{año}) que el contador invoca después.

            $periodo->update([
                'estado'         => PeriodoContable::ESTADO_CERRADO,
                'cerrado_por_id' => $contador->id,
                'cerrado_at'     => now(),
                'motivo_cierre'  => $motivo,
            ]);

            $periodo->refresh();
            event(new PeriodoCerrado($periodo, $contador, $motivo));

            return $periodo;
        });
    }

    /**
     * @return array<int, array{id: string, ok: bool|null, detalle?: string, items?: array<int, mixed>}>
     */
    public function ejecutarChecklist(PeriodoContable $periodo): array
    {
        $borradores = Asiento::query()
            ->where('periodo_id', $periodo->id)
            ->where('estado', Asiento::ESTADO_BORRADOR)
            ->get(['id', 'numero', 'descripcion']);

        $totalDebito = (float) Asiento::query()
            ->where('periodo_id', $periodo->id)
            ->where('estado', Asiento::ESTADO_APROBADO)
            ->join('asiento_items', 'asientos.id', '=', 'asiento_items.asiento_id')
            ->sum('asiento_items.debito');

        $totalCredito = (float) Asiento::query()
            ->where('periodo_id', $periodo->id)
            ->where('estado', Asiento::ESTADO_APROBADO)
            ->join('asiento_items', 'asientos.id', '=', 'asiento_items.asiento_id')
            ->sum('asiento_items.credito');

        $diferencia = round(abs($totalDebito - $totalCredito), 4);

        return [
            [
                'id'      => 'borradores_pendientes',
                'ok'      => $borradores->isEmpty(),
                'detalle' => $borradores->count() . ' asientos en borrador',
                'items'   => $borradores->toArray(),
            ],
            [
                'id'         => 'balance_cuadra',
                'ok'         => $diferencia <= 0.01,
                'detalle'    => "∑D={$totalDebito}, ∑C={$totalCredito}",
                'diferencia' => $diferencia,
            ],
            [
                'id'      => 'conciliacion_bancaria',
                'ok'      => null,
                'detalle' => 'Módulo no disponible en EPIC-002',
            ],
        ];
    }
}
