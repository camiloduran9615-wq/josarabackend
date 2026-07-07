<?php

declare(strict_types=1);

namespace App\Services\Saldos;

use App\Models\Tenant as TenantCentralModel;
use App\Models\Tenant\Asiento;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stancl\Tenancy\Facades\Tenancy;
use Throwable;

/**
 * Recalcula `cuenta_saldos` para un periodo específico de un tenant.
 *
 * Operación de recovery más quirúrgica que BackfillSaldosService:
 *  - Solo toca las filas del periodo indicado (preserva otros periodos).
 *  - Preserva `saldo_inicial_*` (fijados en el cierre del periodo anterior).
 *  - Reinicia `movimiento_debito/credito` a 0 y re-aplica todos los asientos
 *    aprobados del periodo en una única transacción.
 *
 * Casos de uso:
 *  - Un asiento se aprobó pero el listener falló y el saldo no actualizó.
 *  - Corrección post-incidente sin necesidad de backfill completo del tenant.
 *  - Verificación manual: `php artisan saldos:recalcular {tenant} {periodo}`.
 *
 * No idempotente por sí misma para anulaciones: si hay asientos anulados cuyo
 * delta negativo llevó a movimiento < 0, el recálculo los ignora (solo incluye
 * estado='aprobado'). El drift positivo/negativo lo detecta ReconciliarSaldosService.
 */
final class RecalcularPeriodoService
{
    public function __construct(
        private readonly SaldoUpserter $upserter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Recalcula los saldos del periodo indicado para el tenant.
     *
     * @param  string       $tenantId   UUID del tenant
     * @param  string       $periodoId  UUID del periodo contable
     *
     * @return array{periodoId: string, asientos_procesados: int, lineas_procesadas: int}
     *
     * @throws RuntimeException si el tenant no existe o el periodo no tiene asientos
     */
    public function recalcular(string $tenantId, string $periodoId): array
    {
        $tenant = TenantCentralModel::query()->find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$tenantId} no existe.");
        }

        $this->logger->info('Recalcular saldos de periodo iniciado', [
            'tenant_id'  => $tenantId,
            'periodo_id' => $periodoId,
        ]);

        Tenancy::initialize($tenant);

        $asientosProcesados = 0;
        $lineasProcesadas   = 0;

        try {
            DB::transaction(function () use ($periodoId, &$asientosProcesados, &$lineasProcesadas): void {
                // Paso 1: Resetear movimientos del periodo (preservar saldo_inicial_*)
                $this->resetMovimientosPeriodo($periodoId);

                // Paso 2: Re-aplicar todos los asientos aprobados del periodo
                $asientos = Asiento::query()
                    ->where('estado', 'aprobado')
                    ->where('periodo_id', $periodoId)
                    ->with('lineas')
                    ->orderBy('fecha')
                    ->orderBy('id')
                    ->get();

                foreach ($asientos as $asiento) {
                    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant\AsientoLinea> $lineas */
                    $lineas = $asiento->lineas;

                    if ($lineas->isEmpty()) {
                        continue;
                    }

                    $deltas = $this->upserter->agruparLineas(
                        $lineas,
                        $periodoId,
                        $asiento->sucursal_id !== null ? (string) $asiento->sucursal_id : null,
                    );

                    foreach ($deltas as $delta) {
                        $this->upserter->aplicar($delta, invertir: false);
                    }

                    $asientosProcesados++;
                    $lineasProcesadas += $lineas->count();
                }
            });

            Tenancy::end();

            $this->logger->info('Recalcular saldos de periodo completado', [
                'tenant_id'          => $tenantId,
                'periodo_id'         => $periodoId,
                'asientos_procesados' => $asientosProcesados,
                'lineas_procesadas'  => $lineasProcesadas,
            ]);

            return [
                'periodoId'           => $periodoId,
                'asientos_procesados' => $asientosProcesados,
                'lineas_procesadas'   => $lineasProcesadas,
            ];
        } catch (Throwable $e) {
            try {
                Tenancy::end();
            } catch (Throwable) {
                // el throw original tiene prioridad
            }

            $this->logger->error('Recalcular saldos de periodo fallido', [
                'tenant_id'  => $tenantId,
                'periodo_id' => $periodoId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Resetea movimiento_debito y movimiento_credito para todas las filas del periodo.
     * Recalcula saldo_final desde el saldo_inicial (que queda intacto).
     */
    private function resetMovimientosPeriodo(string $periodoId): void
    {
        DB::statement(<<<'SQL'
            UPDATE cuenta_saldos
            SET
                movimiento_debito  = '0.0000',
                movimiento_credito = '0.0000',
                saldo_final_debito  = GREATEST(0,
                    saldo_inicial_debito  - saldo_inicial_credito
                ),
                saldo_final_credito = GREATEST(0,
                    saldo_inicial_credito - saldo_inicial_debito
                ),
                updated_at = NOW()
            WHERE periodo_id = ?
        SQL, [$periodoId]);
    }
}
