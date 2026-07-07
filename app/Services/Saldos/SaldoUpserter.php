<?php

declare(strict_types=1);

namespace App\Services\Saldos;

use App\Models\Tenant\AsientoLinea;
use App\Services\Saldos\DTOs\SaldoDeltaDto;
use App\Support\Bc;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lógica reutilizable de aplicación de deltas sobre `cuenta_saldos`.
 *
 * Usado por:
 *  - `ActualizarSaldosListener` (AsientoAprobado → suma)
 *  - `ReversarSaldosListener`   (AsientoAnulado → resta vía delta inverso)
 *  - `BackfillSaldosService`    (migración inicial de asientos legacy)
 *  - `RecalcularPeriodoService` (recovery manual de saldos)
 *
 * Toda mutación va por UPSERT atómico (ON CONFLICT) en PostgreSQL — Postgres serializa
 * naturalmente las actualizaciones sobre la misma key compuesta sin SELECT FOR UPDATE.
 *
 * Asume que el caller envuelve la operación en una `DB::transaction()`.
 */
final class SaldoUpserter
{
    private const SENTINEL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Agrupa líneas en deltas por (cuenta, tercero, centro_costo, sucursal).
     *
     * Permite procesar un asiento entero en un mínimo de UPSERTs (uno por
     * combinación única). Si dos líneas afectan la misma cuenta con mismos
     * dimensionantes, sus débitos y créditos se suman antes del UPSERT.
     *
     * @param  Collection<int, AsientoLinea>|iterable<int, AsientoLinea>  $lineas
     * @return list<SaldoDeltaDto>
     */
    public function agruparLineas(iterable $lineas, string $periodoId, ?string $sucursalId): array
    {
        /** @var array<string, SaldoDeltaDto> $acumulado */
        $acumulado = [];

        foreach ($lineas as $linea) {
            $clave = $this->claveCompuesta(
                (string) $linea->cuenta_id,
                $linea->tercero_id !== null ? (string) $linea->tercero_id : null,
                $linea->centro_costo_id !== null ? (string) $linea->centro_costo_id : null,
                $sucursalId,
            );

            $previo = $acumulado[$clave] ?? null;

            $acumulado[$clave] = new SaldoDeltaDto(
                cuentaContableId: (string) $linea->cuenta_id,
                periodoId:        $periodoId,
                terceroId:        $linea->tercero_id !== null ? (string) $linea->tercero_id : null,
                centroCostoId:    $linea->centro_costo_id !== null ? (string) $linea->centro_costo_id : null,
                sucursalId:       $sucursalId,
                deltaDebito:      Bc::add($previo !== null ? $previo->deltaDebito  : '0', (string) $linea->debito),
                deltaCredito:     Bc::add($previo !== null ? $previo->deltaCredito : '0', (string) $linea->credito),
            );
        }

        return array_values($acumulado);
    }

    /**
     * Aplica un delta sobre `cuenta_saldos`.
     *
     * @param  bool  $invertir  true para sustraer (caso anular/reversar) — invierte D↔C
     *
     * @throws RuntimeException si tras aplicar queda saldo negativo (corrupción detectada)
     */
    public function aplicar(SaldoDeltaDto $delta, bool $invertir = false): void
    {
        $deltaEfectivo = $invertir
            ? new SaldoDeltaDto(
                cuentaContableId: $delta->cuentaContableId,
                periodoId:        $delta->periodoId,
                terceroId:        $delta->terceroId,
                centroCostoId:    $delta->centroCostoId,
                sucursalId:       $delta->sucursalId,
                // En reverso: el aporte de débito se debe RESTAR del movimiento_debito;
                // el aporte de crédito se RESTA del movimiento_credito.
                // Como el SQL hace "movimiento += EXCLUDED", para sustraer pasamos negativos.
                deltaDebito:      $delta->deltaDebito,
                deltaCredito:     $delta->deltaCredito,
            )
            : $delta;

        $this->upsertSql($deltaEfectivo, $invertir);
        $this->recalcularSaldoFinal($delta);
        $this->validarNoNegativos($delta);
    }

    /**
     * @return non-empty-string
     */
    private function claveCompuesta(string $cuentaId, ?string $terceroId, ?string $ccId, ?string $sucursalId): string
    {
        return implode('|', [
            $cuentaId,
            $terceroId  ?? '_',
            $ccId       ?? '_',
            $sucursalId ?? '_',
        ]);
    }

    /**
     * INSERT ... ON CONFLICT DO UPDATE acumulando movimientos.
     * Si `invertir=true`, el UPDATE resta en lugar de sumar.
     */
    private function upsertSql(SaldoDeltaDto $delta, bool $invertir): void
    {
        $signo = $invertir ? '-' : '+';

        $sql = <<<SQL
            INSERT INTO cuenta_saldos (
                id, cuenta_contable_id, periodo_id, tercero_id, centro_costo_id, sucursal_id,
                saldo_inicial_debito, saldo_inicial_credito,
                movimiento_debito, movimiento_credito,
                saldo_final_debito, saldo_final_credito,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                0, 0,
                ?, ?,
                0, 0,
                NOW(), NOW()
            )
            ON CONFLICT (
                cuenta_contable_id, periodo_id,
                COALESCE(tercero_id,      ?::uuid),
                COALESCE(centro_costo_id, ?::uuid),
                COALESCE(sucursal_id,     ?::uuid)
            )
            DO UPDATE SET
                movimiento_debito  = cuenta_saldos.movimiento_debito  {$signo} EXCLUDED.movimiento_debito,
                movimiento_credito = cuenta_saldos.movimiento_credito {$signo} EXCLUDED.movimiento_credito,
                updated_at         = NOW()
        SQL;

        DB::statement($sql, [
            (string) Str::uuid(),
            $delta->cuentaContableId, $delta->periodoId,
            $delta->terceroId, $delta->centroCostoId, $delta->sucursalId,
            $delta->deltaDebito, $delta->deltaCredito,
            self::SENTINEL_UUID, self::SENTINEL_UUID, self::SENTINEL_UUID,
        ]);
    }

    /**
     * Recalcula saldo_final_debito / saldo_final_credito post-UPSERT.
     * Ambos son MAX(0, ...) y mutuamente excluyentes — solo uno > 0 a la vez.
     */
    private function recalcularSaldoFinal(SaldoDeltaDto $delta): void
    {
        DB::statement(<<<'SQL'
            UPDATE cuenta_saldos cs
            SET
                saldo_final_debito  = GREATEST(0,
                    (cs.saldo_inicial_debito  + cs.movimiento_debito) -
                    (cs.saldo_inicial_credito + cs.movimiento_credito)
                ),
                saldo_final_credito = GREATEST(0,
                    (cs.saldo_inicial_credito + cs.movimiento_credito) -
                    (cs.saldo_inicial_debito  + cs.movimiento_debito)
                ),
                updated_at = NOW()
            WHERE cs.cuenta_contable_id = ?
              AND cs.periodo_id         = ?
              AND cs.tercero_id      IS NOT DISTINCT FROM ?::uuid
              AND cs.centro_costo_id IS NOT DISTINCT FROM ?::uuid
              AND cs.sucursal_id     IS NOT DISTINCT FROM ?::uuid
        SQL, [
            $delta->cuentaContableId,
            $delta->periodoId,
            $delta->terceroId,
            $delta->centroCostoId,
            $delta->sucursalId,
        ]);
    }

    /**
     * Detecta corrupción: si tras una resta los movimientos quedan negativos,
     * es señal de inconsistencia (e.g. anular un asiento cuyos saldos ya no existen).
     *
     * @throws RuntimeException con detalles para AuditLog.
     */
    private function validarNoNegativos(SaldoDeltaDto $delta): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT movimiento_debito, movimiento_credito
            FROM cuenta_saldos
            WHERE cuenta_contable_id = ?
              AND periodo_id         = ?
              AND tercero_id      IS NOT DISTINCT FROM ?::uuid
              AND centro_costo_id IS NOT DISTINCT FROM ?::uuid
              AND sucursal_id     IS NOT DISTINCT FROM ?::uuid
        SQL, [
            $delta->cuentaContableId,
            $delta->periodoId,
            $delta->terceroId,
            $delta->centroCostoId,
            $delta->sucursalId,
        ]);

        if ($row === null) {
            return; // INSERT path puro — no hay nada que validar
        }

        if (Bc::cmp($row->movimiento_debito, '0') < 0 || Bc::cmp($row->movimiento_credito, '0') < 0) {
            throw new RuntimeException(sprintf(
                'Saldo negativo tras reverso en cuenta=%s periodo=%s (D=%s, C=%s). Posible corrupción.',
                $delta->cuentaContableId,
                $delta->periodoId,
                $row->movimiento_debito,
                $row->movimiento_credito,
            ));
        }
    }
}
