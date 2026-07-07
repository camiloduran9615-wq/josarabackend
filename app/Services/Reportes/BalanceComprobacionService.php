<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Tenant as TenantCentralModel;
use App\Services\Reportes\DTOs\BalanceComprobacionDto;
use App\Services\Reportes\DTOs\FilaBalanceComprobacionDto;
use App\Services\Reportes\DTOs\ValidacionBalanceComprobacionDto;
use App\Support\Bc;
use Illuminate\Support\Facades\DB;

/**
 * Genera el Balance de Comprobación (12 columnas) para un periodo contable.
 *
 * Columnas (por cuenta hoja):
 *   SI Débito | SI Crédito  ← saldo final acumulado de periodos previos (on-demand)
 *   Mov Débito | Mov Crédito ← asiento_lineas WHERE tipo_comprobante NOT IN ('AJ','CN','AP')
 *   SF Débito | SF Crédito  = SI + Mov
 *   Aj Débito | Aj Crédito  ← asiento_lineas WHERE tipo_comprobante IN ('AJ','CN','AP')
 *   SA Débito | SA Crédito  = SF + Aj
 *
 * Las 4 igualdades de validación (partida doble):
 *   1) Σ SI_D = Σ SI_C
 *   2) Σ Mov_D = Σ Mov_C
 *   3) Σ Aj_D = Σ Aj_C
 *   4) Σ SA_D = Σ SA_C
 *
 * Saldo Inicial bajo demanda (estándar contable):
 *   - Clases 1, 2, 3 (balance): SUM(saldo_final) de TODOS los periodos previos.
 *   - Clases 4, 5, 6, 7 (resultado): SUM(saldo_final) solo de periodos del mismo
 *     año_fiscal previos al actual (al cierre anual se trasladan a 3606 vía
 *     CierreAnualService, lo que en la práctica los regresa a cero).
 *
 * Esta estrategia evita materializar `saldo_inicial_*` en cada cierre — el SI siempre
 * refleja la realidad del libro mayor sin riesgo de divergencia. Consistente con
 * `BalanceGeneralService::cargarSaldosHasta` (que aplica la misma idea para el BG).
 *
 * `nivel` controla qué cuentas se muestran:
 *   1 → solo cuentas con movimiento (default)
 *   2 → además cuentas con saldo inicial != 0
 *   3 → todas las cuentas activas
 *
 * Cache: `tenant:{tid}:bc:{periodo_codigo}:{hash}` TTL 30 min (periodo activo puede cambiar).
 */
final class BalanceComprobacionService
{
    /** TTL más corto que BG/ER porque el periodo activo puede recibir asientos. */
    private const CACHE_TTL = 1800;

    /** Tipos de comprobante que se consideran ajustes (separados de movimientos ordinarios). */
    private const TIPOS_AJUSTE = ['AJ', 'CN', 'AP'];

    public function __construct(
        private readonly CacheReportesService $cache,
    ) {}

    /**
     * @param  string  $periodoId  UUID del periodo contable
     * @param  int     $nivel      1=solo con mov | 2=con SI | 3=todas
     */
    public function generate(string $periodoId, int $nivel = 1): BalanceComprobacionDto
    {
        $iniciadoAt = microtime(true);
        $tenantId   = (string) (tenant('id') ?? 'central');

        // v2: SI calculado on-demand desde saldo_final de periodos previos
        // (el v1 servía SI=0 cuando el periodo no era el primero — bug corregido).
        $cacheKey = $this->cache->buildKey('bc', $tenantId, [
            'periodo_id' => $periodoId,
            'nivel'      => $nivel,
            'v'          => 3,  // v3: periodos_incluidos agrega mensuales cuando el periodo es anual
        ]);

        $payload = $this->cache->remember($cacheKey, function () use ($periodoId, $nivel): array {
            $periodo = $this->cargarPeriodo($periodoId);
            $rawObjs = $this->cargarFilas($periodoId, $nivel);

            // Normalizar stdClass → array para que el payload sea serializable
            // y consistente cuando viene desde cache (que devuelve arrays, no objetos).
            $filas = [];
            foreach ($rawObjs as $o) {
                $filas[] = (array) $o;
            }

            return [
                'periodo'  => $periodo,
                'filas'    => $filas,
            ];
        }, self::CACHE_TTL);

        /** @var array{codigo: string, nombre: string, fecha_inicio: string, fecha_fin: string|null} $periodo */
        $periodo  = is_array($payload['periodo'] ?? null) ? $payload['periodo'] : ['codigo' => '', 'nombre' => '', 'fecha_inicio' => '', 'fecha_fin' => null];
        /** @var list<array<string, mixed>> $filasRaw */
        $filasRaw = is_array($payload['filas'] ?? null) ? $payload['filas'] : [];

        $tenant      = $this->cargarMetadataTenant($tenantId);
        [$filas, $validacion] = $this->construir($filasRaw);

        $tiempoMs = (int) ((microtime(true) - $iniciadoAt) * 1000);

        return new BalanceComprobacionDto(
            periodoCodigo:    $periodo['codigo'],
            periodoNombre:    $periodo['nombre'],
            desde:            $periodo['fecha_inicio'],
            hasta:            $periodo['fecha_fin'],
            moneda:           'COP',
            tenantRazonSocial: $tenant['razon_social'],
            tenantNit:        $tenant['nit'],
            nivel:            $nivel,
            filas:            $filas,
            validacion:       $validacion,
            generadoAt:       (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            tiempoMs:         $tiempoMs,
            cached:           $tiempoMs < 50,
        );
    }

    /**
     * Query maestra con 4 CTEs:
     *   periodo_actual → metadatos del periodo consultado (fecha_inicio, año_fiscal, tipo)
     *   periodos_incluidos → IDs de periodos a agregar para movs/ajustes:
     *                        - tipo='anual'   → todos los periodos del mismo año_fiscal
     *                                           (incluye mensuales + el anual mismo)
     *                        - tipo='mensual' → solo el periodo consultado
     *   base       → SI calculado como saldo_final acumulado de periodos previos
     *                (clases 1-3: todos los previos; clases 4-7: solo del mismo año_fiscal)
     *   movs       → Movimientos ordinarios de asiento_lineas
     *   ajustes    → Ajustes de asiento_lineas
     *
     * Se realiza un FULL OUTER JOIN implícito via múltiples LEFT JOINs desde cuentas_contables.
     *
     * @return array<int, object>
     */
    private function cargarFilas(string $periodoId, int $nivel): array
    {
        $tiposAjusteList = implode(',', array_map(fn (string $t): string => "'{$t}'", self::TIPOS_AJUSTE));

        $sql = <<<SQL
            WITH periodo_actual AS (
                SELECT id, fecha_inicio, año_fiscal, tipo
                FROM periodos_contables
                WHERE id = ?
            ),
            periodos_incluidos AS (
                -- Si el periodo consultado es ANUAL, agrega todos los periodos del mismo año fiscal
                -- (mensuales + el anual). Esto permite ver el balance comprobación consolidado
                -- post-cierre: las cancelaciones (clases 4-5-6→0) son visibles porque sumamos
                -- los movimientos de operación (mensuales) con los asientos CI (anual).
                -- Si es MENSUAL, solo el periodo consultado.
                SELECT p.id
                FROM periodos_contables p
                CROSS JOIN periodo_actual pa
                WHERE (
                    pa.tipo = 'anual' AND p.año_fiscal = pa.año_fiscal
                ) OR (
                    pa.tipo != 'anual' AND p.id = pa.id
                )
            ),
            prev_acum AS (
                SELECT
                    cs.cuenta_contable_id,
                    SUM(cs.saldo_final_debito)  AS sf_acum_d,
                    SUM(cs.saldo_final_credito) AS sf_acum_c
                FROM cuenta_saldos cs
                INNER JOIN periodos_contables p_prev
                    ON p_prev.id = cs.periodo_id
                INNER JOIN cuentas_contables cc
                    ON cc.id = cs.cuenta_contable_id
                CROSS JOIN periodo_actual pa
                WHERE p_prev.fecha_inicio < pa.fecha_inicio
                  AND p_prev.id NOT IN (SELECT id FROM periodos_incluidos)
                  AND (
                      cc.clase IN (1, 2, 3)
                      OR p_prev.año_fiscal = pa.año_fiscal
                  )
                GROUP BY cs.cuenta_contable_id
            ),
            base AS (
                SELECT
                    cuenta_contable_id,
                    GREATEST(sf_acum_d - sf_acum_c, 0) AS si_d,
                    GREATEST(sf_acum_c - sf_acum_d, 0) AS si_c
                FROM prev_acum
            ),
            movs AS (
                SELECT
                    al.cuenta_id                  AS cuenta_contable_id,
                    SUM(al.debito)                AS mov_d,
                    SUM(al.credito)               AS mov_c
                FROM asiento_items al
                INNER JOIN asientos a
                    ON a.id = al.asiento_id
                WHERE a.periodo_id IN (SELECT id FROM periodos_incluidos)
                  AND a.estado     = 'aprobado'
                  AND a.tipo_comprobante NOT IN ({$tiposAjusteList})
                GROUP BY al.cuenta_id
            ),
            ajustes AS (
                SELECT
                    al.cuenta_id                  AS cuenta_contable_id,
                    SUM(al.debito)                AS aj_d,
                    SUM(al.credito)               AS aj_c
                FROM asiento_items al
                INNER JOIN asientos a
                    ON a.id = al.asiento_id
                WHERE a.periodo_id IN (SELECT id FROM periodos_incluidos)
                  AND a.estado     = 'aprobado'
                  AND a.tipo_comprobante IN ({$tiposAjusteList})
                GROUP BY al.cuenta_id
            )
            SELECT
                cc.codigo                            AS codigo,
                cc.nombre                            AS nombre,
                cc.clase                             AS clase,
                cc.naturaleza                        AS naturaleza,
                COALESCE(b.si_d,    0)::text         AS si_d,
                COALESCE(b.si_c,    0)::text         AS si_c,
                COALESCE(m.mov_d,   0)::text         AS mov_d,
                COALESCE(m.mov_c,   0)::text         AS mov_c,
                COALESCE(aj.aj_d,   0)::text         AS aj_d,
                COALESCE(aj.aj_c,   0)::text         AS aj_c
            FROM cuentas_contables cc
            LEFT JOIN base    b  ON b.cuenta_contable_id  = cc.id
            LEFT JOIN movs    m  ON m.cuenta_contable_id  = cc.id
            LEFT JOIN ajustes aj ON aj.cuenta_contable_id = cc.id
            WHERE cc.activo = true
              AND cc.acepta_movimientos = true
        SQL;

        // Filtro por nivel
        $having = match ($nivel) {
            1 => 'AND (m.cuenta_contable_id IS NOT NULL OR aj.cuenta_contable_id IS NOT NULL)',
            2 => 'AND (b.cuenta_contable_id IS NOT NULL OR m.cuenta_contable_id IS NOT NULL OR aj.cuenta_contable_id IS NOT NULL)',
            default => '', // nivel 3: todas las cuentas activas
        };

        $sql .= "\n{$having}\nORDER BY cc.codigo";

        // Solo un parámetro: periodo_actual. movs/ajustes ahora usan periodos_incluidos.
        return DB::select($sql, [$periodoId]);
    }

    /**
     * Construye la lista de FilaBalanceComprobacionDto y calcula las 4 validaciones.
     *
     * @param  list<array<string, mixed>>  $rawRows
     *
     * @return array{0: list<FilaBalanceComprobacionDto>, 1: ValidacionBalanceComprobacionDto}
     */
    private function construir(array $rawRows): array
    {
        $filas = [];

        // Acumuladores para las 4 validaciones
        $totSiD = '0'; $totSiC  = '0';
        $totMvD = '0'; $totMvC  = '0';
        $totAjD = '0'; $totAjC  = '0';
        $totSaD = '0'; $totSaC  = '0';

        foreach ($rawRows as $r) {
            $siD  = (string) ($r['si_d']  ?? '0');
            $siC  = (string) ($r['si_c']  ?? '0');
            $movD = (string) ($r['mov_d'] ?? '0');
            $movC = (string) ($r['mov_c'] ?? '0');
            $ajD  = (string) ($r['aj_d']  ?? '0');
            $ajC  = (string) ($r['aj_c']  ?? '0');

            // Saldo Final = SI + Mov
            $sfTotal = Bc::add(Bc::add($siD, $movD), Bc::add($siC, $movC)); // total a distribuir
            // Por naturaleza del saldo final: distribución correcta
            $sfD_num = Bc::add($siD, $movD);
            $sfC_num = Bc::add($siC, $movC);
            // Saldo final queda donde excede
            $sfD = Bc::gt($sfD_num, $sfC_num) ? Bc::sub($sfD_num, $sfC_num) : '0.0000';
            $sfC = Bc::gt($sfC_num, $sfD_num) ? Bc::sub($sfC_num, $sfD_num) : '0.0000';

            // Saldo Ajustado = SF + Aj
            $saD_pre = Bc::add($sfD, $ajD);
            $saC_pre = Bc::add($sfC, $ajC);
            $saD = Bc::gt($saD_pre, $saC_pre) ? Bc::sub($saD_pre, $saC_pre) : '0.0000';
            $saC = Bc::gt($saC_pre, $saD_pre) ? Bc::sub($saC_pre, $saD_pre) : '0.0000';

            $filas[] = new FilaBalanceComprobacionDto(
                codigo:              (string) ($r['codigo']    ?? ''),
                nombre:              (string) ($r['nombre']    ?? ''),
                clase:               (int)    ($r['clase']     ?? 0),
                naturaleza:          (string) ($r['naturaleza'] ?? 'debito'),
                saldoInicialDebito:  $siD,
                saldoInicialCredito: $siC,
                movimientoDebito:    $movD,
                movimientoCredito:   $movC,
                saldoFinalDebito:    $sfD,
                saldoFinalCredito:   $sfC,
                ajusteDebito:        $ajD,
                ajusteCredito:       $ajC,
                saldoAjustadoDebito: $saD,
                saldoAjustadoCredito:$saC,
            );

            // Acumular totales de validación
            $totSiD = Bc::add($totSiD, $siD);
            $totSiC = Bc::add($totSiC, $siC);
            $totMvD = Bc::add($totMvD, $movD);
            $totMvC = Bc::add($totMvC, $movC);
            $totAjD = Bc::add($totAjD, $ajD);
            $totAjC = Bc::add($totAjC, $ajC);
            $totSaD = Bc::add($totSaD, $saD);
            $totSaC = Bc::add($totSaC, $saC);
        }

        $deltaSi  = Bc::abs(Bc::sub($totSiD, $totSiC));
        $deltaMov = Bc::abs(Bc::sub($totMvD, $totMvC));
        $deltaAj  = Bc::abs(Bc::sub($totAjD, $totAjC));
        $deltaSa  = Bc::abs(Bc::sub($totSaD, $totSaC));
        $tol      = Bc::TOLERANCIA_COP;

        $siOk  = Bc::cmp($deltaSi,  $tol, 2) <= 0;
        $movOk = Bc::cmp($deltaMov, $tol, 2) <= 0;
        $ajOk  = Bc::cmp($deltaAj,  $tol, 2) <= 0;
        $saOk  = Bc::cmp($deltaSa,  $tol, 2) <= 0;

        $validacion = new ValidacionBalanceComprobacionDto(
            totalSiDebito:   $totSiD,
            totalSiCredito:  $totSiC,
            deltaSi:         $deltaSi,
            siBalanceado:    $siOk,
            totalMovDebito:  $totMvD,
            totalMovCredito: $totMvC,
            deltaMov:        $deltaMov,
            movBalanceado:   $movOk,
            totalAjDebito:   $totAjD,
            totalAjCredito:  $totAjC,
            deltaAj:         $deltaAj,
            ajBalanceado:    $ajOk,
            totalSaDebito:   $totSaD,
            totalSaCredito:  $totSaC,
            deltaSa:         $deltaSa,
            saBalanceado:    $saOk,
            valido:          $siOk && $movOk && $ajOk && $saOk,
        );

        return [$filas, $validacion];
    }

    /**
     * @return array{codigo: string, nombre: string, fecha_inicio: string, fecha_fin: string|null}
     */
    private function cargarPeriodo(string $periodoId): array
    {
        $row = DB::selectOne(
            'SELECT codigo, tipo, año_fiscal, mes, fecha_inicio::text, fecha_fin::text FROM periodos_contables WHERE id = ?',
            [$periodoId],
        );

        if ($row === null) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())
                ->setModel(\App\Models\Tenant\PeriodoContable::class, [$periodoId]);
        }

        // Construir nombre descriptivo desde tipo + año_fiscal + mes
        $tipo  = (string) $row->tipo;
        $anio  = (string) $row->año_fiscal;
        $mes   = $row->mes !== null ? (int) $row->mes : null;
        $nombre = $tipo === 'mensual' && $mes !== null
            ? sprintf('%s-%02d (mensual)', $anio, $mes)
            : sprintf('%s (anual)', $anio);

        return [
            'codigo'       => (string) $row->codigo,
            'nombre'       => $nombre,
            'fecha_inicio' => (string) $row->fecha_inicio,
            'fecha_fin'    => $row->fecha_fin !== null ? (string) $row->fecha_fin : null,
        ];
    }

    /**
     * @return array{razon_social: string, nit: string}
     */
    private function cargarMetadataTenant(string $tenantId): array
    {
        $tenant = TenantCentralModel::query()->find($tenantId);

        return [
            'razon_social' => $tenant !== null ? (string) ($tenant->razon_social ?? '') : '',
            'nit'          => $tenant !== null ? (string) ($tenant->nit          ?? '') : '',
        ];
    }
}
