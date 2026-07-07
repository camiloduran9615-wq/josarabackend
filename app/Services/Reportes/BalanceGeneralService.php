<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Tenant as TenantCentralModel;
use App\Services\Reportes\DTOs\BalanceGeneralDto;
use App\Services\Reportes\DTOs\CuentaSaldoBalanceDto;
use App\Services\Reportes\DTOs\EcuacionBalanceDto;
use App\Services\Reportes\DTOs\GrupoBalanceDto;
use App\Services\Reportes\DTOs\SeccionBalanceDto;
use App\Services\Reportes\DTOs\SeccionTotalDto;
use App\Support\Bc;
use Illuminate\Support\Facades\DB;

/**
 * Genera el Balance General (Estado de Situación Financiera — NIC 1).
 *
 * Estrategia: UNA query maestra con JOIN entre `cuentas_contables` y `cuenta_saldos`
 * agregando movimientos hasta `fecha_corte` (filtrado por periodos cuyo fecha_fin <=
 * fecha_corte). Cero N+1 — la jerarquía clase → grupo → cuenta se reconstruye en PHP
 * a partir del set ya cargado.
 *
 * Estructura NIIF:
 *   ACTIVO (clase 1):
 *     Corriente   ← clasificacion_balance = 'corriente'
 *     No Corriente ← clasificacion_balance = 'no_corriente'
 *   PASIVO (clase 2): igual sub-división
 *   PATRIMONIO (clase 3): sin sub-divisiones
 *
 * Comparativo: si `comparativo_año_anterior=true`, ejecuta UNA segunda query maestra
 * para la fecha_corte un año atrás. Cost: 2× la query base — aceptable.
 *
 * Cache: `tenant:{tid}:bg:{fecha_corte}:{hash}` TTL 1h (Arquitecto §6.4).
 */
final class BalanceGeneralService
{
    public function __construct(
        private readonly CacheReportesService $cache,
    ) {}

    /**
     * @param  string  $fechaCorte  'YYYY-MM-DD'
     */
    public function generate(string $fechaCorte, bool $comparativoAnioAnterior = false): BalanceGeneralDto
    {
        $iniciadoAt = microtime(true);

        $tenantId   = (string) (tenant('id') ?? 'central');
        $cacheKey   = $this->cache->buildKey('bg', $tenantId, [
            'fecha_corte' => $fechaCorte,
            'comparativo' => $comparativoAnioAnterior,
        ]);

        $payload = $this->cache->remember($cacheKey, function () use ($fechaCorte, $comparativoAnioAnterior): array {
            $rowsActual = $this->cargarSaldosHasta($fechaCorte);

            $fechaComparativo = null;
            $rowsAnterior     = [];
            if ($comparativoAnioAnterior) {
                $fechaComparativo = $this->fechaUnAnioAtras($fechaCorte);
                $rowsAnterior     = $this->cargarSaldosHasta($fechaComparativo);
            }

            return [
                'rows_actual'       => $rowsActual,
                'rows_anterior'     => $rowsAnterior,
                'fecha_comparativo' => $fechaComparativo,
            ];
        }, ttl: 3600);

        /** @var list<array<string, mixed>> $rowsActual */
        $rowsActual = $payload['rows_actual'] ?? [];
        /** @var list<array<string, mixed>> $rowsAnterior */
        $rowsAnterior = $payload['rows_anterior'] ?? [];
        $fechaComparativo = isset($payload['fecha_comparativo']) && is_string($payload['fecha_comparativo'])
            ? $payload['fecha_comparativo']
            : null;

        $tenant      = $this->cargarMetadataTenant($tenantId);
        $estructura  = $this->ensamblarEstructura(
            rowsActual:   $rowsActual,
            rowsAnterior: $rowsAnterior,
            comparativo:  $comparativoAnioAnterior,
        );

        $tiempoMs = (int) ((microtime(true) - $iniciadoAt) * 1000);

        return new BalanceGeneralDto(
            fechaCorte:        $fechaCorte,
            fechaComparativo:  $fechaComparativo,
            moneda:            'COP',
            tenantRazonSocial: $tenant['razon_social'],
            tenantNit:         $tenant['nit'],
            activo:            $estructura['activo'],
            pasivo:            $estructura['pasivo'],
            patrimonio:        $estructura['patrimonio'],
            ecuacion:          $estructura['ecuacion'],
            generadoAt:        (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            tiempoMs:          $tiempoMs,
            cached:            $tiempoMs < 50, // heurística: si fue < 50ms vino de cache
        );
    }

    /**
     * Query maestra: suma de movimientos por cuenta hasta `fecha_corte`.
     *
     * Considera todos los periodos cuya `fecha_fin <= fecha_corte` (es decir, periodos
     * cerrados o cuya fecha de fin ya pasó). Para periodos que abarcan `fecha_corte` a
     * mitad, en MVP usamos saldo final del periodo — el contador puede iterar a v1.1 con
     * proporcionalidad si lo necesita.
     *
     * @return list<array{
     *     codigo: string, nombre: string, clase: int, naturaleza: string,
     *     clasif_balance: ?string, saldo: string,
     * }>
     */
    private function cargarSaldosHasta(string $fechaCorte): array
    {
        // Sub-query primero: agregar saldos por cuenta de periodos cuya fecha_fin <= fechaCorte.
        // Luego LEFT JOIN con cuentas_contables para que aparezcan TODAS las cuentas
        // (incluso si no tienen saldos — útil si queremos mostrar ceros en debug).
        $sql = <<<'SQL'
            WITH saldos_acumulados AS (
                SELECT
                    cs.cuenta_contable_id,
                    SUM(cs.movimiento_debito  + cs.saldo_inicial_debito)  AS total_debito,
                    SUM(cs.movimiento_credito + cs.saldo_inicial_credito) AS total_credito
                FROM cuenta_saldos cs
                INNER JOIN periodos_contables p
                    ON p.id = cs.periodo_id
                WHERE p.fecha_inicio <= ?::date
                GROUP BY cs.cuenta_contable_id
            )
            SELECT
                cc.id                                AS cuenta_id,
                cc.codigo                            AS codigo,
                cc.nombre                            AS nombre,
                cc.clase                             AS clase,
                cc.naturaleza                        AS naturaleza,
                cc.clasificacion_balance             AS clasif_balance,
                COALESCE(sa.total_debito,  0)::text  AS total_debito,
                COALESCE(sa.total_credito, 0)::text  AS total_credito
            FROM cuentas_contables cc
            LEFT JOIN saldos_acumulados sa
                ON sa.cuenta_contable_id = cc.id
            WHERE cc.activo = true
              AND cc.clase IN (1, 2, 3, 4, 5, 6, 7)
              AND cc.acepta_movimientos = true
            ORDER BY cc.codigo
        SQL;

        $rows = DB::select($sql, [$fechaCorte]);

        $resultado = [];
        foreach ($rows as $r) {
            $debito     = (string) $r->total_debito;
            $credito    = (string) $r->total_credito;
            $naturaleza = (string) $r->naturaleza;

            $saldoNeto = $naturaleza === 'debito'
                ? Bc::sub($debito, $credito)
                : Bc::sub($credito, $debito);

            if (Bc::cmp($saldoNeto, '0', 2) === 0) {
                continue;
            }

            $resultado[] = [
                'codigo'         => (string) $r->codigo,
                'nombre'         => (string) $r->nombre,
                'clase'          => (int) $r->clase,
                'naturaleza'     => $naturaleza,
                'clasif_balance' => $r->clasif_balance !== null ? (string) $r->clasif_balance : null,
                'saldo'          => $saldoNeto,
            ];
        }

        return $resultado;
    }

    /**
     * Reconstruye la jerarquía Activo/Pasivo/Patrimonio desde un set plano de cuentas.
     *
     * @param  list<array<string, mixed>>  $rowsActual
     * @param  list<array<string, mixed>>  $rowsAnterior
     *
     * @return array{
     *     activo: SeccionTotalDto,
     *     pasivo: SeccionTotalDto,
     *     patrimonio: SeccionTotalDto,
     *     ecuacion: EcuacionBalanceDto,
     * }
     */
    private function ensamblarEstructura(array $rowsActual, array $rowsAnterior, bool $comparativo): array
    {
        // Index rows anteriores por código de cuenta para lookup O(1)
        $anteriorPorCodigo = [];
        foreach ($rowsAnterior as $r) {
            $anteriorPorCodigo[(string) $r['codigo']] = (string) $r['saldo'];
        }

        $activo     = $this->construirSeccionPorClase(1, $rowsActual, $anteriorPorCodigo, $comparativo, subdividirCorriente: true);
        $pasivo     = $this->construirSeccionPorClase(2, $rowsActual, $anteriorPorCodigo, $comparativo, subdividirCorriente: true);
        $patrimonio = $this->construirSeccionPorClase(3, $rowsActual, $anteriorPorCodigo, $comparativo, subdividirCorriente: false);

        // NIIF — Reportes intermedios: incluir Resultado del Ejercicio en el patrimonio
        // (saldo neto de cuentas 4 Ingresos − 5 Gastos − 6 Costos − 7 Producción)
        // antes del cierre formal. Si no se hace, la ecuación contable no cuadra durante el año.
        $patrimonio = $this->incluirResultadoEjercicio($patrimonio, $rowsActual);

        $pasivoMasPat = Bc::add($pasivo->total, $patrimonio->total);
        $diferencia   = Bc::sub($activo->total, $pasivoMasPat);
        $balanceado   = Bc::cmp(Bc::abs($diferencia), Bc::TOLERANCIA_COP, 2) <= 0;

        return [
            'activo'     => $activo,
            'pasivo'     => $pasivo,
            'patrimonio' => $patrimonio,
            'ecuacion'   => new EcuacionBalanceDto(
                activo:              $activo->total,
                pasivoMasPatrimonio: $pasivoMasPat,
                diferencia:          $diferencia,
                balanceado:          $balanceado,
            ),
        ];
    }

    /**
     * Inyecta el "Resultado del Ejercicio" como cuenta virtual del patrimonio.
     *
     * Antes del cierre formal, las cuentas 4 (ingresos), 5 (gastos), 6 (costos), 7 (producción)
     * tienen saldos abiertos. Para que el Balance General (NIC 1) cuadre en reportes intermedios,
     * sumamos esos saldos como "Resultado del Ejercicio (provisional)" en el patrimonio.
     *
     * Utilidad = Ingresos (clase 4) − Gastos (clase 5) − Costos (clase 6) − Producción (clase 7)
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function incluirResultadoEjercicio(SeccionTotalDto $patrimonio, array $rows): SeccionTotalDto
    {
        $ingresos = '0';
        $costosGastos = '0';

        foreach ($rows as $r) {
            $clase = (int) ($r['clase'] ?? 0);
            if (!in_array($clase, [4, 5, 6, 7], true)) continue;

            // Saldo ya viene normalizado por naturaleza: positivo = saldo normal.
            // Para ingresos (clase 4, naturaleza CR) saldo positivo = ingreso real.
            // Para gastos/costos (5,6,7 — naturaleza DR) saldo positivo = gasto real.
            $saldo = (string) ($r['saldo'] ?? '0');
            if ($clase === 4) {
                $ingresos = Bc::add($ingresos, $saldo);
            } else {
                $costosGastos = Bc::add($costosGastos, $saldo);
            }
        }

        $resultado = Bc::sub($ingresos, $costosGastos);
        if (Bc::cmp($resultado, '0', 2) === 0) {
            return $patrimonio;  // Sin movimientos del periodo — no agrega nada
        }

        // Construir GrupoBalanceDto + cuenta virtual "3605 Resultado del Ejercicio"
        $nombre = Bc::cmp($resultado, '0', 2) > 0 ? 'Utilidad del Ejercicio (provisional)' : 'Pérdida del Ejercicio (provisional)';

        $cuentaVirtual = (object) [
            'codigo'     => '3605',
            'nombre'     => $nombre,
            'naturaleza' => 'credito',
            'saldo'      => $resultado,
            'saldo_anterior' => null,
        ];

        $grupoResultado = new GrupoBalanceDto(
            codigo: '3605',
            nombre: $nombre,
            total: $resultado,
            totalAnterior: null,
            cuentas: [$cuentaVirtual],
        );

        // Buscar la subsección "Patrimonio" (siempre hay una en clase 3 sin sub-corriente)
        // y agregar el grupo virtual.
        $nuevasSubsecciones = [];
        foreach ($patrimonio->subsecciones as $sub) {
            $nuevosGrupos = array_merge($sub->grupos, [$grupoResultado]);
            $nuevoTotal   = Bc::add($sub->total, $resultado);
            $nuevasSubsecciones[] = new SeccionBalanceDto(
                nombre:        $sub->nombre,
                total:         $nuevoTotal,
                totalAnterior: $sub->totalAnterior,
                grupos:        $nuevosGrupos,
            );
        }

        // Si no había subsecciones (caso edge), crear una nueva
        if (empty($nuevasSubsecciones)) {
            $nuevasSubsecciones[] = new SeccionBalanceDto(
                nombre:        'Patrimonio',
                total:         $resultado,
                totalAnterior: null,
                grupos:        [$grupoResultado],
            );
        }

        return new SeccionTotalDto(
            total:          Bc::add($patrimonio->total, $resultado),
            totalAnterior:  $patrimonio->totalAnterior,
            subsecciones:   $nuevasSubsecciones,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>       $anteriorPorCodigo
     */
    private function construirSeccionPorClase(
        int $clase,
        array $rows,
        array $anteriorPorCodigo,
        bool $comparativo,
        bool $subdividirCorriente,
    ): SeccionTotalDto {
        $cuentasClase = array_filter($rows, fn (array $r): bool => ((int) ($r['clase'] ?? 0)) === $clase);

        if ($subdividirCorriente) {
            $corrientes    = array_filter($cuentasClase, fn (array $r): bool => ($r['clasif_balance'] ?? null) === 'corriente');
            $noCorrientes  = array_filter($cuentasClase, fn (array $r): bool => ($r['clasif_balance'] ?? null) === 'no_corriente');
            $sinClasificar = array_filter($cuentasClase, fn (array $r): bool => $r['clasif_balance'] === null || $r['clasif_balance'] === 'na');

            $subsecciones = [];
            $totalGeneral = '0';
            $totalAntGen  = $comparativo ? '0' : null;

            foreach ([
                ['nombre' => $clase === 1 ? 'Activos Corrientes'    : 'Pasivos Corrientes',    'set' => $corrientes],
                ['nombre' => $clase === 1 ? 'Activos No Corrientes' : 'Pasivos No Corrientes', 'set' => $noCorrientes],
                ['nombre' => 'Sin clasificar', 'set' => $sinClasificar],
            ] as $bloque) {
                if ($bloque['set'] === []) {
                    continue;
                }
                $seccion = $this->construirSeccionDesdeRows(
                    $bloque['nombre'],
                    $bloque['set'],
                    $anteriorPorCodigo,
                    $comparativo,
                );
                $subsecciones[] = $seccion;
                $totalGeneral   = Bc::add($totalGeneral, $seccion->total);
                if ($comparativo && $seccion->totalAnterior !== null) {
                    $totalAntGen = Bc::add($totalAntGen ?? '0', $seccion->totalAnterior);
                }
            }

            return new SeccionTotalDto(
                total:         $totalGeneral,
                totalAnterior: $totalAntGen,
                subsecciones:  $subsecciones,
            );
        }

        // Patrimonio: una sola sub-sección
        $seccion = $this->construirSeccionDesdeRows('Patrimonio', $cuentasClase, $anteriorPorCodigo, $comparativo);

        return new SeccionTotalDto(
            total:         $seccion->total,
            totalAnterior: $seccion->totalAnterior,
            subsecciones:  [$seccion],
        );
    }

    /**
     * Construye una `SeccionBalanceDto` agrupando las cuentas por los primeros 2 dígitos
     * del código (grupo PUC).
     *
     * @param  iterable<int|string, array<string, mixed>>  $cuentasRows
     * @param  array<string, string>                       $anteriorPorCodigo
     */
    private function construirSeccionDesdeRows(
        string $nombre,
        iterable $cuentasRows,
        array $anteriorPorCodigo,
        bool $comparativo,
    ): SeccionBalanceDto {
        /** @var array<string, array{nombre: string, cuentas: list<CuentaSaldoBalanceDto>, total: string, total_anterior: string}> $grupos */
        $grupos       = [];
        $totalSeccion = '0';
        $totalAntSec  = $comparativo ? '0' : null;

        foreach ($cuentasRows as $r) {
            $codigo      = (string) ($r['codigo'] ?? '');
            $saldo       = (string) ($r['saldo']  ?? '0');
            $codigoGrupo = substr($codigo, 0, 2);

            if (! isset($grupos[$codigoGrupo])) {
                $grupos[$codigoGrupo] = [
                    'nombre'         => $this->nombreGrupo($codigoGrupo),
                    'cuentas'        => [],
                    'total'          => '0',
                    'total_anterior' => '0',
                ];
            }

            $saldoAnterior = $comparativo ? ($anteriorPorCodigo[$codigo] ?? '0') : null;

            $grupos[$codigoGrupo]['cuentas'][] = new CuentaSaldoBalanceDto(
                codigo:        $codigo,
                nombre:        (string) ($r['nombre']     ?? ''),
                clase:         (int)    ($r['clase']      ?? 0),
                naturaleza:    (string) ($r['naturaleza'] ?? 'debito'),
                saldo:         $saldo,
                saldoAnterior: $saldoAnterior,
            );

            $grupos[$codigoGrupo]['total'] = Bc::add($grupos[$codigoGrupo]['total'], $saldo);
            if ($comparativo && $saldoAnterior !== null) {
                $grupos[$codigoGrupo]['total_anterior'] = Bc::add($grupos[$codigoGrupo]['total_anterior'], $saldoAnterior);
            }

            $totalSeccion = Bc::add($totalSeccion, $saldo);
            if ($comparativo && $saldoAnterior !== null) {
                $totalAntSec = Bc::add($totalAntSec ?? '0', $saldoAnterior);
            }
        }

        // Convertir a list<GrupoBalanceDto> ordenado por código
        ksort($grupos);
        $gruposDto = [];
        foreach ($grupos as $codigo => $g) {
            $gruposDto[] = new GrupoBalanceDto(
                codigo:        (string) $codigo,
                nombre:        $g['nombre'],
                total:         $g['total'],
                totalAnterior: $g['total_anterior'] !== '0' ? $g['total_anterior'] : null,
                cuentas:       $g['cuentas'],
            );
        }

        return new SeccionBalanceDto(
            nombre:        $nombre,
            total:         $totalSeccion,
            totalAnterior: $totalAntSec,
            grupos:        $gruposDto,
        );
    }

    /**
     * Mapeo estático de códigos de grupo PUC a nombres canónicos (Decreto 2650).
     */
    private function nombreGrupo(string $codigo): string
    {
        return match ($codigo) {
            '11' => 'Disponible',
            '12' => 'Inversiones',
            '13' => 'Deudores',
            '14' => 'Inventarios',
            '15' => 'Propiedad, Planta y Equipo',
            '16' => 'Intangibles',
            '17' => 'Diferidos',
            '18' => 'Otros Activos',
            '21' => 'Obligaciones Financieras',
            '22' => 'Proveedores',
            '23' => 'Cuentas por Pagar',
            '24' => 'Impuestos por Pagar',
            '25' => 'Obligaciones Laborales',
            '26' => 'Pasivos Estimados',
            '27' => 'Diferidos',
            '28' => 'Otros Pasivos',
            '31' => 'Capital Social',
            '32' => 'Superávit de Capital',
            '33' => 'Reservas',
            '36' => 'Resultados del Ejercicio',
            '37' => 'Resultados Acumulados',
            '38' => 'Superávit por Revaluación',
            default => "Grupo {$codigo}",
        };
    }

    private function fechaUnAnioAtras(string $fechaCorte): string
    {
        return (new \DateTimeImmutable($fechaCorte))->modify('-1 year')->format('Y-m-d');
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
