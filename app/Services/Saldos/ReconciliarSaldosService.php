<?php

declare(strict_types=1);

namespace App\Services\Saldos;

use App\Events\Saldos\SaldosInconsistenciaDetectada;
use App\Models\Tenant as TenantCentralModel;
use App\Services\Saldos\DTOs\AnomaliaSaldoDto;
use App\Services\Saldos\DTOs\ReconciliacionResultDto;
use App\Support\Bc;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stancl\Tenancy\Facades\Tenancy;

/**
 * Verifica que `cuenta_saldos` (materializado) coincida exactamente con la suma
 * real de `asiento_lineas` aprobados.
 *
 * Uso típico:
 *  - Job nocturno `ReconciliarSaldosJob` corre `--all` a las 02:00 hora Colombia
 *  - El admin puede correrlo on-demand vía `artisan saldos:reconciliar <tenant>`
 *
 * Estrategia SQL: FULL OUTER JOIN entre `cuenta_saldos` y SUM agrupado de
 * `asiento_lineas` con `IS NOT DISTINCT FROM` en las dimensiones nullables.
 * Esto detecta TRES tipos de anomalía:
 *   1) Materializado tiene movimientos que el real no tiene (fila huérfana en cs)
 *   2) Real tiene movimientos que el materializado no captura (falta fila en cs)
 *   3) Ambos existen pero los montos difieren
 *
 * Tolerancia: 0.01 COP (1 centavo) — para absorber redondeos legítimos de IVA por línea.
 * Cualquier delta superior es anomalía y dispara `SaldosInconsistenciaDetectada`.
 *
 * NO repara automáticamente. La reparación requiere intervención humana vía
 * `php artisan saldos:backfill <tenant> --fresh` tras investigar la causa raíz.
 */
final class ReconciliarSaldosService
{
    public const TOLERANCIA = '0.01';

    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ejecuta la reconciliación para un tenant.
     *
     * @param  string       $tenantId
     * @param  string|null  $periodoId  Filtra por periodo (opcional; default = todos)
     *
     * @throws RuntimeException si el tenant no existe
     */
    public function reconciliar(string $tenantId, ?string $periodoId = null): ReconciliacionResultDto
    {
        $tenant = TenantCentralModel::query()->find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$tenantId} no existe.");
        }

        $iniciadoAt = new \DateTimeImmutable();

        Tenancy::initialize($tenant);
        try {
            $resumen = $this->ejecutarComparacion($periodoId);
        } finally {
            Tenancy::end();
        }

        $finalizadoAt = new \DateTimeImmutable();

        $resultado = new ReconciliacionResultDto(
            tenantId:          $tenantId,
            periodoId:         $periodoId,
            filasComparadas:   $resumen['filas_comparadas'],
            anomaliasCount:    count($resumen['anomalias']),
            deltaDebitoTotal:  $resumen['delta_debito_total'],
            deltaCreditoTotal: $resumen['delta_credito_total'],
            anomalias:         $resumen['anomalias'],
            iniciadoAt:        $iniciadoAt,
            finalizadoAt:      $finalizadoAt,
        );

        if (! $resultado->estaLimpio()) {
            $this->logger->warning('Reconciliación de saldos: drift detectado', [
                'tenant_id'          => $tenantId,
                'periodo_id'         => $periodoId,
                'anomalias'          => $resultado->anomaliasCount,
                'delta_debito_total' => $resultado->deltaDebitoTotal,
                'delta_credito_total'=> $resultado->deltaCreditoTotal,
                'duracion_segundos'  => $resultado->duracionSegundos(),
            ]);
            $this->events->dispatch(new SaldosInconsistenciaDetectada($resultado));
        } else {
            $this->logger->info('Reconciliación de saldos: limpia', [
                'tenant_id'         => $tenantId,
                'periodo_id'        => $periodoId,
                'filas_comparadas'  => $resultado->filasComparadas,
                'duracion_segundos' => $resultado->duracionSegundos(),
            ]);
        }

        return $resultado;
    }

    /**
     * SQL principal: FULL OUTER JOIN entre la materialización y la fuente de verdad.
     *
     * @return array{
     *   filas_comparadas: int,
     *   anomalias: list<AnomaliaSaldoDto>,
     *   delta_debito_total: string,
     *   delta_credito_total: string,
     * }
     */
    private function ejecutarComparacion(?string $periodoId): array
    {
        $filtroPeriodo = $periodoId !== null ? 'AND a.periodo_id = ?' : '';
        $bindingsRe    = $periodoId !== null ? [$periodoId] : [];

        $sql = <<<SQL
            WITH reales AS (
                SELECT
                    al.cuenta_id           AS cuenta_contable_id,
                    a.periodo_id           AS periodo_id,
                    al.tercero_id          AS tercero_id,
                    al.centro_costo_id     AS centro_costo_id,
                    a.sucursal_id          AS sucursal_id,
                    SUM(al.debito)         AS real_debito,
                    SUM(al.credito)        AS real_credito
                FROM asiento_items al
                INNER JOIN asientos a ON a.id = al.asiento_id
                WHERE a.estado = 'aprobado'
                  {$filtroPeriodo}
                GROUP BY al.cuenta_id, a.periodo_id, al.tercero_id, al.centro_costo_id, a.sucursal_id
            ),
            materializado AS (
                SELECT
                    cs.id                   AS cuenta_saldo_id,
                    cs.cuenta_contable_id,
                    cs.periodo_id,
                    cs.tercero_id,
                    cs.centro_costo_id,
                    cs.sucursal_id,
                    cs.movimiento_debito    AS mat_debito,
                    cs.movimiento_credito   AS mat_credito
                FROM cuenta_saldos cs
            )
            SELECT
                COALESCE(m.cuenta_saldo_id,    gen_random_uuid())                  AS cuenta_saldo_id,
                COALESCE(m.cuenta_contable_id, r.cuenta_contable_id)               AS cuenta_contable_id,
                COALESCE(m.periodo_id,         r.periodo_id)                       AS periodo_id,
                COALESCE(m.tercero_id,         r.tercero_id)                       AS tercero_id,
                COALESCE(m.centro_costo_id,    r.centro_costo_id)                  AS centro_costo_id,
                COALESCE(m.sucursal_id,        r.sucursal_id)                      AS sucursal_id,
                COALESCE(m.mat_debito,  0)::text                                   AS mat_debito,
                COALESCE(m.mat_credito, 0)::text                                   AS mat_credito,
                COALESCE(r.real_debito,  0)::text                                  AS real_debito,
                COALESCE(r.real_credito, 0)::text                                  AS real_credito,
                ABS(COALESCE(m.mat_debito,  0) - COALESCE(r.real_debito,  0))::text AS delta_debito,
                ABS(COALESCE(m.mat_credito, 0) - COALESCE(r.real_credito, 0))::text AS delta_credito
            FROM materializado m
            FULL OUTER JOIN reales r
                ON r.cuenta_contable_id = m.cuenta_contable_id
                AND r.periodo_id         = m.periodo_id
                AND r.tercero_id      IS NOT DISTINCT FROM m.tercero_id
                AND r.centro_costo_id IS NOT DISTINCT FROM m.centro_costo_id
                AND r.sucursal_id     IS NOT DISTINCT FROM m.sucursal_id
            WHERE
                ABS(COALESCE(m.mat_debito,  0) - COALESCE(r.real_debito,  0)) > ?::numeric
             OR ABS(COALESCE(m.mat_credito, 0) - COALESCE(r.real_credito, 0)) > ?::numeric
        SQL;

        $bindings = array_merge($bindingsRe, [self::TOLERANCIA, self::TOLERANCIA]);

        $rows = DB::select($sql, $bindings);

        // Conteo total de comparaciones (solo para reporting)
        $filasMaterializado = (int) DB::scalar(
            $periodoId !== null
                ? 'SELECT COUNT(*) FROM cuenta_saldos WHERE periodo_id = ?'
                : 'SELECT COUNT(*) FROM cuenta_saldos',
            $periodoId !== null ? [$periodoId] : []
        );

        $anomalias        = [];
        $totalDeltaDebito = '0';
        $totalDeltaCredito = '0';

        // Para mapear cuenta_contable_id → codigo de cuenta, cargar una sola vez
        $cuentaIdsUnicos = array_values(array_unique(array_filter(array_map(
            fn ($row) => (string) $row->cuenta_contable_id,
            $rows,
        ))));
        /** @var array<string, string> $codigosCuenta */
        $codigosCuenta = $cuentaIdsUnicos === []
            ? []
            : DB::table('cuentas_contables')
                ->whereIn('id', $cuentaIdsUnicos)
                ->pluck('codigo', 'id')
                ->map(fn ($v): string => (string) $v)
                ->all();

        foreach ($rows as $row) {
            $anomalias[] = new AnomaliaSaldoDto(
                cuentaSaldoId:                  (string) $row->cuenta_saldo_id,
                cuentaCodigo:                   $codigosCuenta[(string) $row->cuenta_contable_id] ?? '(?)',
                periodoId:                      (string) $row->periodo_id,
                terceroId:                      $row->tercero_id !== null ? (string) $row->tercero_id : null,
                movimientoDebitoMaterializado:  (string) $row->mat_debito,
                movimientoCreditoMaterializado: (string) $row->mat_credito,
                movimientoDebitoReal:           (string) $row->real_debito,
                movimientoCreditoReal:          (string) $row->real_credito,
                deltaDebito:                    (string) $row->delta_debito,
                deltaCredito:                   (string) $row->delta_credito,
            );
            $totalDeltaDebito  = Bc::add($totalDeltaDebito,  (string) $row->delta_debito);
            $totalDeltaCredito = Bc::add($totalDeltaCredito, (string) $row->delta_credito);
        }

        return [
            'filas_comparadas'     => $filasMaterializado,
            'anomalias'            => $anomalias,
            'delta_debito_total'   => $totalDeltaDebito,
            'delta_credito_total'  => $totalDeltaCredito,
        ];
    }
}
