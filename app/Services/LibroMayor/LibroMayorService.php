<?php

declare(strict_types=1);

namespace App\Services\LibroMayor;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\CuentaSaldo;
use App\Services\LibroMayor\DTOs\LibroMayorResultDto;
use App\Services\LibroMayor\DTOs\MovimientoLibroMayorDto;
use App\Services\Reportes\CacheReportesService;
use App\Support\Bc;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Servicio de lectura (CQRS query side) del Libro Mayor de una cuenta.
 *
 * Entrega:
 *   - Saldos AGREGADOS (suma de filas en cuenta_saldos que matchean los filtros)
 *   - Movimientos cronológicos (asiento_lineas APROBADAS) con saldo acumulado por fila
 *
 * Performance:
 *   - Saldos: 1 query SUM agrupado, cacheable.
 *   - Movimientos: 1 query con JOIN asientos→lineas→tercero→centro_costo, paginado.
 *
 * Cache: `tenant:{tid}:lm:{cuenta_id}:{hash_filtros}` TTL 30 min (Arquitecto §6.4).
 * Invalida `InvalidarCacheReportesListener` al aprobar/anular asientos.
 */
final class LibroMayorService
{
    public const PER_PAGE_DEFAULT = 50;
    public const PER_PAGE_MAX     = 200;

    public function __construct(
        private readonly CacheReportesService $cache,
    ) {}

    /**
     * @param  array{
     *     periodo_id?: ?string,
     *     desde?: ?string,
     *     hasta?: ?string,
     *     tercero_id?: ?string,
     *     centro_costo_id?: ?string,
     *     sucursal_id?: ?string,
     *     incluir_movimientos?: bool,
     *     page?: int,
     *     per_page?: int,
     * }  $filtros
     *
     * @throws RuntimeException si la cuenta no existe en el tenant inicializado
     */
    public function query(string $cuentaId, array $filtros = []): LibroMayorResultDto
    {
        $cuenta = CuentaContable::query()->find($cuentaId);
        if ($cuenta === null) {
            throw new RuntimeException("Cuenta {$cuentaId} no encontrada.");
        }

        $filtrosNorm = $this->normalizarFiltros($filtros);
        $tenantId    = (string) (tenant('id') ?? 'central');
        $cacheKey    = $this->cache->buildKey('lm', $tenantId, [
            'cuenta_id' => $cuentaId,
            'filtros'   => $filtrosNorm,
        ]);

        $payload = $this->cache->remember($cacheKey, function () use ($cuenta, $filtrosNorm): array {
            $saldos       = $this->calcularSaldos($cuenta->id, $filtrosNorm);
            $movimientos  = $filtrosNorm['incluir_movimientos']
                ? $this->cargarMovimientos($cuenta, $filtrosNorm, $saldos)
                : ['rows' => [], 'paginacion' => $this->paginacionVacia($filtrosNorm)];

            return [
                'cuenta' => [
                    'id'         => (string) $cuenta->id,
                    'codigo'     => (string) $cuenta->codigo,
                    'nombre'     => (string) $cuenta->nombre,
                    'naturaleza' => (string) $cuenta->naturaleza,
                    'clase'      => $cuenta->getAttribute('clase') !== null ? (int) $cuenta->getAttribute('clase') : null,
                ],
                'filtros'      => $this->filtrosParaRespuesta($filtrosNorm),
                'saldos'       => $saldos,
                'movimientos'  => $movimientos['rows'],
                'paginacion'   => $movimientos['paginacion'],
            ];
        }, ttl: 1800); // 30 min

        /** @var list<MovimientoLibroMayorDto> $movimientosDto */
        $movimientosDto = array_values(array_map(
            static fn (array $m): MovimientoLibroMayorDto => new MovimientoLibroMayorDto(
                    asientoId:             $m['asiento_id'],
                    asientoNumero:         $m['asiento_numero'],
                    fecha:                 $m['fecha'],
                    tipoComprobante:       $m['tipo_comprobante'],
                    descripcionLinea:      $m['descripcion_linea'],
                    documentoReferencia:   $m['documento_referencia'],
                    terceroId:             $m['tercero_id'],
                    terceroNombre:         $m['tercero_nombre'],
                    terceroIdentificacion: $m['tercero_identificacion'],
                    centroCostoId:         $m['centro_costo_id'],
                    centroCostoCodigo:     $m['centro_costo_codigo'],
                    debito:                $m['debito'],
                    credito:               $m['credito'],
                    saldoAcumulado:        $m['saldo_acumulado'],
                ),
                $payload['movimientos'],
        ));

        return new LibroMayorResultDto(
            cuenta:      $payload['cuenta'],
            filtros:     $payload['filtros'],
            saldos:      $payload['saldos'],
            movimientos: $movimientosDto,
            paginacion:  $payload['paginacion'],
        );
    }

    /**
     * Agrega saldos desde `cuenta_saldos` que matchean los filtros.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{
     *     saldo_inicial_debito: string, saldo_inicial_credito: string,
     *     movimiento_debito: string, movimiento_credito: string,
     *     saldo_final_debito: string, saldo_final_credito: string
     * }
     */
    private function calcularSaldos(string $cuentaId, array $filtros): array
    {
        $query = CuentaSaldo::query()->where('cuenta_contable_id', $cuentaId);

        if (! empty($filtros['periodo_id'])) {
            $query->where('periodo_id', $filtros['periodo_id']);
        }
        if (! empty($filtros['tercero_id'])) {
            $query->where('tercero_id', $filtros['tercero_id']);
        }
        if (! empty($filtros['centro_costo_id'])) {
            $query->where('centro_costo_id', $filtros['centro_costo_id']);
        }
        if (! empty($filtros['sucursal_id'])) {
            $query->where('sucursal_id', $filtros['sucursal_id']);
        }

        $row = $query->selectRaw(<<<'SQL'
                COALESCE(SUM(saldo_inicial_debito),  0) AS si_d,
                COALESCE(SUM(saldo_inicial_credito), 0) AS si_c,
                COALESCE(SUM(movimiento_debito),     0) AS mov_d,
                COALESCE(SUM(movimiento_credito),    0) AS mov_c,
                COALESCE(SUM(saldo_final_debito),    0) AS sf_d,
                COALESCE(SUM(saldo_final_credito),   0) AS sf_c
            SQL)->first();

        return [
            'saldo_inicial_debito'  => (string) ($row->si_d  ?? '0'),
            'saldo_inicial_credito' => (string) ($row->si_c  ?? '0'),
            'movimiento_debito'     => (string) ($row->mov_d ?? '0'),
            'movimiento_credito'    => (string) ($row->mov_c ?? '0'),
            'saldo_final_debito'    => (string) ($row->sf_d  ?? '0'),
            'saldo_final_credito'   => (string) ($row->sf_c  ?? '0'),
        ];
    }

    /**
     * Carga movimientos cronológicos paginados con saldo acumulado.
     *
     * Para no recalcular el saldo desde el principio en cada página, el saldo acumulado
     * de la PÁGINA empieza desde el saldo "anterior" de la fecha de corte (saldo inicial
     * del periodo + suma de movimientos previos a la página). Ese cálculo se hace en SQL
     * con una window function para evitar N+1.
     *
     * @param  array<string, mixed>  $filtros
     * @param  array<string, string> $saldos
     *
     * @return array{rows: list<array<string, mixed>>, paginacion: array{total: int, page: int, per_page: int, last_page: int}}
     */
    private function cargarMovimientos(CuentaContable $cuenta, array $filtros, array $saldos): array
    {
        $page    = max(1, (int) ($filtros['page']     ?? 1));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($filtros['per_page'] ?? self::PER_PAGE_DEFAULT)));
        $offset  = ($page - 1) * $perPage;

        // WHERE dinámico
        $where     = ["a.estado = 'aprobado'", 'al.cuenta_id = ?'];
        $bindings  = [$cuenta->id];

        if (! empty($filtros['periodo_id'])) {
            $where[] = 'a.periodo_id = ?';
            $bindings[] = $filtros['periodo_id'];
        }
        if (! empty($filtros['desde'])) {
            $where[] = 'a.fecha >= ?';
            $bindings[] = $filtros['desde'];
        }
        if (! empty($filtros['hasta'])) {
            $where[] = 'a.fecha <= ?';
            $bindings[] = $filtros['hasta'];
        }
        if (! empty($filtros['tercero_id'])) {
            $where[] = 'al.tercero_id = ?';
            $bindings[] = $filtros['tercero_id'];
        }
        if (! empty($filtros['centro_costo_id'])) {
            $where[] = 'al.centro_costo_id = ?';
            $bindings[] = $filtros['centro_costo_id'];
        }
        if (! empty($filtros['sucursal_id'])) {
            $where[] = 'a.sucursal_id = ?';
            $bindings[] = $filtros['sucursal_id'];
        }
        $whereSql = implode(' AND ', $where);

        $signo = $cuenta->naturaleza === 'debito' ? '+' : '-';
        $signoInverso = $cuenta->naturaleza === 'debito' ? '-' : '+';
        $saldoInicial = Bc::sub(
            $saldos['saldo_inicial_debito'],
            $saldos['saldo_inicial_credito'],
            Bc::SCALE_INTERNAL,
        );
        if ($cuenta->naturaleza === 'credito') {
            $saldoInicial = Bc::sub('0', $saldoInicial, Bc::SCALE_INTERNAL);
        }

        // Conteo total (separado para paginación)
        $total = (int) DB::scalar(
            "SELECT COUNT(*) FROM asiento_items al INNER JOIN asientos a ON a.id = al.asiento_id WHERE {$whereSql}",
            $bindings,
        );

        // Cargar movimientos con window function para saldo acumulado
        // Orden cronológico estable: (fecha, numero, asiento_items.id)
        // $saldoInicial viene de bcmath sobre saldos de BD — sin riesgo de inyección.
        // Inline en SQL para evitar conflicto entre `?::numeric` y bindings posicionales.
        $saldoInicialSeguro = number_format((float) $saldoInicial, 4, '.', '');

        $sql = <<<SQL
            SELECT
                al.id                                AS linea_id,
                a.id                                 AS asiento_id,
                a.numero                             AS asiento_numero,
                a.fecha                              AS fecha,
                a.tipo_comprobante                   AS tipo_comprobante,
                al.descripcion_item                  AS descripcion_linea,
                al.documento_referencia              AS documento_referencia,
                al.tercero_id                        AS tercero_id,
                t.razon_social                       AS tercero_nombre,
                t.identificacion              AS tercero_identificacion,
                al.centro_costo_id                   AS centro_costo_id,
                cc.codigo                            AS centro_costo_codigo,
                al.debito::text                      AS debito,
                al.credito::text                     AS credito,
                (
                    {$saldoInicialSeguro}::numeric
                    {$signo} SUM(al.debito)  OVER (ORDER BY a.fecha, a.numero NULLS LAST, al.id ROWS UNBOUNDED PRECEDING)
                    {$signoInverso} SUM(al.credito) OVER (ORDER BY a.fecha, a.numero NULLS LAST, al.id ROWS UNBOUNDED PRECEDING)
                )::text                              AS saldo_acumulado
            FROM asiento_items al
            INNER JOIN asientos a    ON a.id = al.asiento_id
            LEFT JOIN terceros t     ON t.id = al.tercero_id
            LEFT JOIN centros_costo cc ON cc.id = al.centro_costo_id
            WHERE {$whereSql}
            ORDER BY a.fecha, a.numero NULLS LAST, al.id
            LIMIT ? OFFSET ?
        SQL;

        $bindingsFinales = array_merge($bindings, [$perPage, $offset]);
        $rows = DB::select($sql, $bindingsFinales);

        /** @var list<array<string, mixed>> $movimientos */
        $movimientos = array_values(array_map(
            static fn ($r): array => [
                'asiento_id'             => (string) $r->asiento_id,
                'asiento_numero'         => $r->asiento_numero !== null ? (string) $r->asiento_numero : null,
                'fecha'                  => (string) $r->fecha,
                'tipo_comprobante'       => (string) $r->tipo_comprobante,
                'descripcion_linea'      => $r->descripcion_linea !== null ? (string) $r->descripcion_linea : null,
                'documento_referencia'   => $r->documento_referencia !== null ? (string) $r->documento_referencia : null,
                'tercero_id'             => $r->tercero_id !== null ? (string) $r->tercero_id : null,
                'tercero_nombre'         => $r->tercero_nombre !== null ? (string) $r->tercero_nombre : null,
                'tercero_identificacion' => $r->tercero_identificacion !== null ? (string) $r->tercero_identificacion : null,
                'centro_costo_id'        => $r->centro_costo_id !== null ? (string) $r->centro_costo_id : null,
                'centro_costo_codigo'    => $r->centro_costo_codigo !== null ? (string) $r->centro_costo_codigo : null,
                'debito'                 => (string) $r->debito,
                'credito'                => (string) $r->credito,
                'saldo_acumulado'        => (string) $r->saldo_acumulado,
            ],
            $rows,
        ));

        /** @var array{total: int, page: int, per_page: int, last_page: int} $paginacion */
        $paginacion = [
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $perPage,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];

        return ['rows' => $movimientos, 'paginacion' => $paginacion];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function normalizarFiltros(array $filtros): array
    {
        return [
            'periodo_id'           => $filtros['periodo_id']      ?? null,
            'desde'                => $filtros['desde']           ?? null,
            'hasta'                => $filtros['hasta']           ?? null,
            'tercero_id'           => $filtros['tercero_id']      ?? null,
            'centro_costo_id'      => $filtros['centro_costo_id'] ?? null,
            'sucursal_id'          => $filtros['sucursal_id']     ?? null,
            'incluir_movimientos'  => (bool) ($filtros['incluir_movimientos'] ?? true),
            'page'                 => max(1, (int) ($filtros['page']     ?? 1)),
            'per_page'             => min(self::PER_PAGE_MAX, max(1, (int) ($filtros['per_page'] ?? self::PER_PAGE_DEFAULT))),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{
     *     periodo_id: ?string, tercero_id: ?string, centro_costo_id: ?string,
     *     sucursal_id: ?string, desde: ?string, hasta: ?string,
     * }
     */
    private function filtrosParaRespuesta(array $filtros): array
    {
        return [
            'periodo_id'      => $filtros['periodo_id']      !== null ? (string) $filtros['periodo_id']      : null,
            'tercero_id'      => $filtros['tercero_id']      !== null ? (string) $filtros['tercero_id']      : null,
            'centro_costo_id' => $filtros['centro_costo_id'] !== null ? (string) $filtros['centro_costo_id'] : null,
            'sucursal_id'     => $filtros['sucursal_id']     !== null ? (string) $filtros['sucursal_id']     : null,
            'desde'           => $filtros['desde']           !== null ? (string) $filtros['desde']           : null,
            'hasta'           => $filtros['hasta']           !== null ? (string) $filtros['hasta']           : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{total: int, page: int, per_page: int, last_page: int}
     */
    private function paginacionVacia(array $filtros): array
    {
        return [
            'total'     => 0,
            'page'      => (int) $filtros['page'],
            'per_page'  => (int) $filtros['per_page'],
            'last_page' => 1,
        ];
    }
}
