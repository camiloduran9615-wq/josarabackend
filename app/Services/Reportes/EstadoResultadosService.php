<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Tenant as TenantCentralModel;
use App\Services\Reportes\DTOs\BloqueEstadoResultadosDto;
use App\Services\Reportes\DTOs\EstadoResultadosDto;
use App\Services\Reportes\DTOs\LineaEstadoResultadosDto;
use App\Support\Bc;
use Illuminate\Support\Facades\DB;

/**
 * Genera el Estado de Resultados (P&G) por FUNCIÓN — NIC 1 párr. 103.
 *
 * Clasificación por función (recomendada para SaaS por el Contador):
 *   Ingresos Operacionales        (clase 4, clasificacion_pyg='operacional' o NULL)
 *   - Costo de Ventas             (clases 6 + 7)
 *   = Utilidad Bruta
 *   - Gastos Operacionales        (clase 5, clasificacion_pyg='operacional', excluyendo 5405xx)
 *   = Utilidad Operacional
 *   ± Otros Ingresos/Egresos      (clase 4/5, clasificacion_pyg='no_operacional')
 *   = Utilidad Antes de Impuesto
 *   - Impuesto de Renta           (clase 5, codigo LIKE '5405%')
 *   = Utilidad Neta del Ejercicio
 *
 * Query: UNA sola CTE agrupa movimientos de todos los periodos que caigan dentro
 * del rango [desde, hasta]. Los saldos iniciales NO se incluyen (son del P&G del
 * periodo anterior — solo movimientos del periodo vigente).
 *
 * Cache: `tenant:{tid}:er:{desde}:{hasta}:{hash}` TTL 1h.
 */
final class EstadoResultadosService
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly CacheReportesService $cache,
    ) {}

    /**
     * @param  string  $desde  'YYYY-MM-DD'
     * @param  string  $hasta  'YYYY-MM-DD'
     */
    public function generate(
        string $desde,
        string $hasta,
        bool $comparativo = false,
    ): EstadoResultadosDto {
        $iniciadoAt = microtime(true);
        $tenantId   = (string) (tenant('id') ?? 'central');

        $cacheKey = $this->cache->buildKey('er', $tenantId, [
            'desde'       => $desde,
            'hasta'       => $hasta,
            'comparativo' => $comparativo,
        ]);

        $payload = $this->cache->remember($cacheKey, function () use ($desde, $hasta, $comparativo): array {
            $rowsActual = $this->cargarMovimientosPeriodo($desde, $hasta);

            $desdeComp = null;
            $hastaComp = null;
            $rowsComp  = [];
            if ($comparativo) {
                $desdeComp = $this->mismoRangoAnioAtras($desde);
                $hastaComp = $this->mismoRangoAnioAtras($hasta);
                $rowsComp  = $this->cargarMovimientosPeriodo($desdeComp, $hastaComp);
            }

            return [
                'rows_actual' => $rowsActual,
                'rows_comp'   => $rowsComp,
                'desde_comp'  => $desdeComp,
                'hasta_comp'  => $hastaComp,
            ];
        }, self::CACHE_TTL);

        /** @var list<array<string, mixed>> $rowsActual */
        $rowsActual = $payload['rows_actual'] ?? [];
        /** @var list<array<string, mixed>> $rowsComp */
        $rowsComp  = $payload['rows_comp'] ?? [];
        $desdeComp = isset($payload['desde_comp']) && is_string($payload['desde_comp']) ? $payload['desde_comp'] : null;
        $hastaComp = isset($payload['hasta_comp']) && is_string($payload['hasta_comp']) ? $payload['hasta_comp'] : null;

        $tenant     = $this->cargarMetadataTenant($tenantId);
        $estructura = $this->ensamblarEstructura($rowsActual, $rowsComp, $comparativo);

        $tiempoMs = (int) ((microtime(true) - $iniciadoAt) * 1000);

        return new EstadoResultadosDto(
            desde:                          $desde,
            hasta:                          $hasta,
            desdeComparativo:               $desdeComp,
            hastaComparativo:               $hastaComp,
            moneda:                         'COP',
            tenantRazonSocial:              $tenant['razon_social'],
            tenantNit:                      $tenant['nit'],
            ingresos:                       $estructura['ingresos'],
            costoVentas:                    $estructura['costo_ventas'],
            utilidadBruta:                  $estructura['utilidad_bruta'],
            utilidadBrutaComparativa:       $estructura['utilidad_bruta_comp'],
            gastosOperacionales:            $estructura['gastos_operacionales'],
            utilidadOperacional:            $estructura['utilidad_operacional'],
            utilidadOperacionalComparativa: $estructura['utilidad_operacional_comp'],
            otrosIngresosEgresos:           $estructura['otros'],
            utilidadAntesImpuesto:          $estructura['utilidad_antes_impuesto'],
            utilidadAntesImpuestoComparativa: $estructura['utilidad_antes_impuesto_comp'],
            impuestoRenta:                  $estructura['impuesto_renta'],
            utilidadNeta:                   $estructura['utilidad_neta'],
            utilidadNetaComparativa:        $estructura['utilidad_neta_comp'],
            generadoAt:                     (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            tiempoMs:                       $tiempoMs,
            cached:                         $tiempoMs < 50,
        );
    }

    /**
     * Carga los movimientos del P&G para periodos cuya fecha_inicio y fecha_fin caen
     * dentro del rango [desde, hasta]. NO incluye saldo_inicial (es carry-forward).
     *
     * @return list<array{
     *     codigo: string, nombre: string, clase: int,
     *     naturaleza: string, clasif_pyg: ?string,
     *     mov_debito: string, mov_credito: string,
     * }>
     */
    private function cargarMovimientosPeriodo(string $desde, string $hasta): array
    {
        $sql = <<<'SQL'
            WITH movs AS (
                SELECT
                    cs.cuenta_contable_id,
                    SUM(cs.movimiento_debito)  AS mov_debito,
                    SUM(cs.movimiento_credito) AS mov_credito
                FROM cuenta_saldos cs
                INNER JOIN periodos_contables p
                    ON p.id = cs.periodo_id
                WHERE p.fecha_inicio >= ?::date
                  AND (p.fecha_fin IS NULL OR p.fecha_fin <= ?::date)
                GROUP BY cs.cuenta_contable_id
            )
            SELECT
                cc.codigo                            AS codigo,
                cc.nombre                            AS nombre,
                cc.clase                             AS clase,
                cc.naturaleza                        AS naturaleza,
                cc.clasificacion_pyg                 AS clasif_pyg,
                COALESCE(m.mov_debito,  0)::text     AS mov_debito,
                COALESCE(m.mov_credito, 0)::text     AS mov_credito
            FROM cuentas_contables cc
            INNER JOIN movs m
                ON m.cuenta_contable_id = cc.id
            WHERE cc.activo = true
              AND cc.clase IN (4, 5, 6, 7)
              AND cc.acepta_movimientos = true
            ORDER BY cc.codigo
        SQL;

        $rows = DB::select($sql, [$desde, $hasta]);

        $resultado = [];
        foreach ($rows as $r) {
            $debito  = (string) $r->mov_debito;
            $credito = (string) $r->mov_credito;

            // Saldo cero → no presentar en el reporte
            if (Bc::cmp($debito, '0', 4) === 0 && Bc::cmp($credito, '0', 4) === 0) {
                continue;
            }

            $resultado[] = [
                'codigo'     => (string) $r->codigo,
                'nombre'     => (string) $r->nombre,
                'clase'      => (int) $r->clase,
                'naturaleza' => (string) $r->naturaleza,
                'clasif_pyg' => $r->clasif_pyg !== null ? (string) $r->clasif_pyg : null,
                'mov_debito' => $debito,
                'mov_credito'=> $credito,
            ];
        }

        return $resultado;
    }

    /**
     * Clasifica una fila en uno de los bloques del P&G por función.
     *
     * Retorna: 'ingresos' | 'costo_ventas' | 'gastos_operacionales' |
     *          'otros_ingresos' | 'otros_egresos' | 'impuesto_renta'
     */
    private function clasificarFila(int $clase, ?string $clasificacionPyg, string $codigo): string
    {
        if ($clase === 6 || $clase === 7) {
            return 'costo_ventas';
        }

        if ($clase === 4) {
            return $clasificacionPyg === 'no_operacional' ? 'otros_ingresos' : 'ingresos';
        }

        if ($clase === 5) {
            // 5405xx = Gasto de Impuesto de Renta (NIC 12) — bloque separado siempre
            if (str_starts_with($codigo, '5405')) {
                return 'impuesto_renta';
            }

            return $clasificacionPyg === 'no_operacional' ? 'otros_egresos' : 'gastos_operacionales';
        }

        return 'gastos_operacionales'; // fallback seguro
    }

    /**
     * Calcula el saldo presentable de una cuenta según su naturaleza y movimientos.
     * Ingresos (crédito): excedente crédito → positivo.
     * Gastos/Costos (débito): excedente débito → positivo.
     */
    private function saldoPresentable(string $naturaleza, string $movDebito, string $movCredito): string
    {
        return $naturaleza === 'credito'
            ? Bc::sub($movCredito, $movDebito)
            : Bc::sub($movDebito, $movCredito);
    }

    /**
     * Ensambla la estructura jerárquica del P&G a partir de rows planos.
     *
     * @param  list<array<string, mixed>>  $rowsActual
     * @param  list<array<string, mixed>>  $rowsComp
     *
     * @return array{
     *     ingresos: BloqueEstadoResultadosDto,
     *     costo_ventas: BloqueEstadoResultadosDto,
     *     utilidad_bruta: string, utilidad_bruta_comp: ?string,
     *     gastos_operacionales: BloqueEstadoResultadosDto,
     *     utilidad_operacional: string, utilidad_operacional_comp: ?string,
     *     otros: BloqueEstadoResultadosDto,
     *     utilidad_antes_impuesto: string, utilidad_antes_impuesto_comp: ?string,
     *     impuesto_renta: BloqueEstadoResultadosDto,
     *     utilidad_neta: string, utilidad_neta_comp: ?string,
     * }
     */
    private function ensamblarEstructura(array $rowsActual, array $rowsComp, bool $comparativo): array
    {
        // Index comparativo por código
        $compPorCodigo = [];
        foreach ($rowsComp as $r) {
            $nat = (string) ($r['naturaleza'] ?? 'debito');
            $compPorCodigo[(string) ($r['codigo'] ?? '')] = $this->saldoPresentable(
                $nat,
                (string) ($r['mov_debito']  ?? '0'),
                (string) ($r['mov_credito'] ?? '0'),
            );
        }

        // Acumuladores por bloque
        $bloques = [
            'ingresos'             => [],
            'costo_ventas'         => [],
            'gastos_operacionales' => [],
            'otros_ingresos'       => [],
            'otros_egresos'        => [],
            'impuesto_renta'       => [],
        ];

        foreach ($rowsActual as $r) {
            $clase      = (int) ($r['clase']      ?? 5);
            $clasifPyg  = isset($r['clasif_pyg']) && is_string($r['clasif_pyg']) ? $r['clasif_pyg'] : null;
            $codigo     = (string) ($r['codigo']  ?? '');
            $nombre     = (string) ($r['nombre']  ?? '');
            $naturaleza = (string) ($r['naturaleza'] ?? 'debito');
            $movD       = (string) ($r['mov_debito']  ?? '0');
            $movC       = (string) ($r['mov_credito'] ?? '0');

            $bloque = $this->clasificarFila($clase, $clasifPyg, $codigo);
            $saldo  = $this->saldoPresentable($naturaleza, $movD, $movC);
            $saldoComp = $comparativo ? ($compPorCodigo[$codigo] ?? '0') : null;

            $bloques[$bloque][] = new LineaEstadoResultadosDto(
                codigo:           $codigo,
                nombre:           $nombre,
                saldo:            $saldo,
                saldoComparativo: $saldoComp,
            );
        }

        $buildBloque = function (string $clave, string $codigoDto, string $nombreDto) use ($bloques, $comparativo): BloqueEstadoResultadosDto {
            /** @var list<LineaEstadoResultadosDto> $lineas */
            $lineas = $bloques[$clave];
            $total  = '0';
            $totalC = $comparativo ? '0' : null;

            foreach ($lineas as $l) {
                $total  = Bc::add($total, $l->saldo);
                if ($comparativo && $l->saldoComparativo !== null) {
                    $totalC = Bc::add($totalC ?? '0', $l->saldoComparativo);
                }
            }

            return new BloqueEstadoResultadosDto(
                codigo:          $codigoDto,
                nombre:          $nombreDto,
                total:           $total,
                totalComparativo: $totalC,
                lineas:          $lineas,
            );
        };

        $ingresos          = $buildBloque('ingresos',             '4',  'Ingresos Operacionales');
        $costoVentas       = $buildBloque('costo_ventas',         '67', 'Costo de Ventas');
        $gastosOp          = $buildBloque('gastos_operacionales', '5op','Gastos Operacionales');
        $impuesto          = $buildBloque('impuesto_renta',       '5405','Impuesto de Renta');

        // Otros: fusión de otros_ingresos (suman) y otros_egresos (restan)
        $otrosIngrLineas = $bloques['otros_ingresos'];
        $otrosEgrLineas  = $bloques['otros_egresos'];
        $totalOtrosI = '0';
        $totalOtrosE = '0';
        $totalOtrosIC = $comparativo ? '0' : null;
        $totalOtrosEC = $comparativo ? '0' : null;

        foreach ($otrosIngrLineas as $l) {
            $totalOtrosI  = Bc::add($totalOtrosI, $l->saldo);
            if ($comparativo && $l->saldoComparativo !== null) {
                $totalOtrosIC = Bc::add($totalOtrosIC ?? '0', $l->saldoComparativo);
            }
        }
        foreach ($otrosEgrLineas as $l) {
            $totalOtrosE  = Bc::add($totalOtrosE, $l->saldo);
            if ($comparativo && $l->saldoComparativo !== null) {
                $totalOtrosEC = Bc::add($totalOtrosEC ?? '0', $l->saldoComparativo);
            }
        }

        // Net otros = otros_ingresos - otros_egresos (positivo si hay ganancia)
        $totalOtros     = Bc::sub($totalOtrosI, $totalOtrosE);
        $totalOtrosComp = $comparativo
            ? Bc::sub($totalOtrosIC ?? '0', $totalOtrosEC ?? '0')
            : null;

        /** @var list<LineaEstadoResultadosDto> $otrosLineas */
        $otrosLineas = array_merge($otrosIngrLineas, $otrosEgrLineas);
        $otros = new BloqueEstadoResultadosDto(
            codigo:          'OT',
            nombre:          'Otros Ingresos y Egresos (No Operacionales)',
            total:           $totalOtros,
            totalComparativo: $totalOtrosComp,
            lineas:          $otrosLineas,
        );

        // Cálculos en cadena
        $utilBruta    = Bc::sub($ingresos->total, $costoVentas->total);
        $utilBrutaC   = $comparativo
            ? Bc::sub($ingresos->totalComparativo ?? '0', $costoVentas->totalComparativo ?? '0')
            : null;

        $utilOp       = Bc::sub($utilBruta, $gastosOp->total);
        $utilOpC      = $comparativo
            ? Bc::sub($utilBrutaC ?? '0', $gastosOp->totalComparativo ?? '0')
            : null;

        $utilAntesImp = Bc::add($utilOp, $otros->total);
        $utilAntesImpC = $comparativo
            ? Bc::add($utilOpC ?? '0', $otros->totalComparativo ?? '0')
            : null;

        $utilNeta     = Bc::sub($utilAntesImp, $impuesto->total);
        $utilNetaC    = $comparativo
            ? Bc::sub($utilAntesImpC ?? '0', $impuesto->totalComparativo ?? '0')
            : null;

        return [
            'ingresos'                    => $ingresos,
            'costo_ventas'                => $costoVentas,
            'utilidad_bruta'              => $utilBruta,
            'utilidad_bruta_comp'         => $utilBrutaC,
            'gastos_operacionales'        => $gastosOp,
            'utilidad_operacional'        => $utilOp,
            'utilidad_operacional_comp'   => $utilOpC,
            'otros'                       => $otros,
            'utilidad_antes_impuesto'     => $utilAntesImp,
            'utilidad_antes_impuesto_comp'=> $utilAntesImpC,
            'impuesto_renta'              => $impuesto,
            'utilidad_neta'               => $utilNeta,
            'utilidad_neta_comp'          => $utilNetaC,
        ];
    }

    private function mismoRangoAnioAtras(string $fecha): string
    {
        return (new \DateTimeImmutable($fecha))->modify('-1 year')->format('Y-m-d');
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
