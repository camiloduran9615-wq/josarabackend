<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Notas a los Estados Financieros (NIC 1.117).
 *
 * Genera el desglose por cuenta (subcuenta de 6 dígitos) para cada
 * agrupación de los estados financieros. Cada nota incluye:
 *   - Saldo al cierre del año
 *   - Saldo al cierre del año anterior (comparativo)
 *   - Variación absoluta y porcentual
 *
 * Notas estándar generadas:
 *   N4  Efectivo y equivalentes        (clase 11)
 *   N5  Deudores comerciales            (clase 13)
 *   N6  Inventarios                     (clase 14)
 *   N7  Propiedad, planta y equipo      (clase 15)
 *   N8  Obligaciones financieras        (clase 21)
 *   N9  Proveedores                     (clase 22)
 *   N10 Cuentas por pagar e impuestos   (clases 23 y 24)
 *   N11 Obligaciones laborales          (clase 25)
 *   N12 Patrimonio                      (clase 3)
 *   N13 Ingresos operacionales          (clase 4)
 *   N14 Costos                          (clase 6)
 *   N15 Gastos                          (clase 5)
 *
 * Las notas 1-3 y 16 (información general, bases, políticas, hechos
 * posteriores) son texto libre — quedan a cargo del contador. Pueden
 * gestionarse en futuras iteraciones via tabla `notas_ef_textos` o similar.
 */
class NotasEstadosFinancierosService
{
    /**
     * Definición de notas: número → [título, filtro de cuentas].
     * Cada filtro es un array con 'grupos' (LIKE en 2 dígitos) o 'codigos' (LIKE exact).
     *
     * @var array<int, array{titulo: string, grupos: array<int, string>, signo?: int}>
     */
    private const NOTAS = [
        4  => ['titulo' => 'Efectivo y Equivalentes de Efectivo', 'grupos' => ['11'], 'signo' => 1],
        5  => ['titulo' => 'Deudores Comerciales y Otras Cuentas por Cobrar', 'grupos' => ['13'], 'signo' => 1],
        6  => ['titulo' => 'Inventarios', 'grupos' => ['14'], 'signo' => 1],
        7  => ['titulo' => 'Propiedad, Planta y Equipo', 'grupos' => ['15'], 'signo' => 1],
        8  => ['titulo' => 'Obligaciones Financieras', 'grupos' => ['21'], 'signo' => -1],
        9  => ['titulo' => 'Proveedores', 'grupos' => ['22'], 'signo' => -1],
        10 => ['titulo' => 'Cuentas por Pagar e Impuestos', 'grupos' => ['23', '24'], 'signo' => -1],
        11 => ['titulo' => 'Obligaciones Laborales', 'grupos' => ['25'], 'signo' => -1],
        12 => ['titulo' => 'Patrimonio',
            'grupos' => ['31', '32', '33', '34', '36', '37', '38'],
            'signo'  => -1,
        ],
        13 => ['titulo' => 'Ingresos Operacionales', 'grupos' => ['41', '42'], 'signo' => -1],
        14 => ['titulo' => 'Costos de Ventas', 'grupos' => ['61', '62'], 'signo' => 1],
        15 => ['titulo' => 'Gastos Operacionales',
            'grupos' => ['51', '52', '53'],
            'signo'  => 1,
        ],
    ];

    public function generate(int $anio): array
    {
        $fechaCorte    = sprintf('%04d-12-31', $anio);
        $fechaCorteAnt = sprintf('%04d-12-31', $anio - 1);

        // Para clases 1, 2, 3 (balance): saldo acumulado hasta fechaCorte
        // Para clases 4, 5, 6 (resultado): movimientos del año
        $saldosBalance = $this->saldosBalanceHasta($fechaCorte);
        $saldosBalanceAnt = $this->saldosBalanceHasta($fechaCorteAnt);

        $movsResultado = $this->movimientosResultadoEnAnio($anio);
        $movsResultadoAnt = $this->movimientosResultadoEnAnio($anio - 1);

        $notas = [];
        foreach (self::NOTAS as $numero => $def) {
            $cuentas = $this->cuentasDeGrupos($def['grupos']);
            if ($cuentas->isEmpty()) {
                // Si no hay cuentas en el grupo, igualmente reportamos la nota vacía.
                $notas[] = $this->notaVacia($numero, $def['titulo']);
                continue;
            }

            $esResultado = $this->esGrupoResultado($def['grupos']);
            $fuenteActual   = $esResultado ? $movsResultado    : $saldosBalance;
            $fuenteAnterior = $esResultado ? $movsResultadoAnt : $saldosBalanceAnt;
            $signo = $def['signo'] ?? 1;

            $filas = [];
            $totalActual = 0.0;
            $totalAnterior = 0.0;

            foreach ($cuentas as $cc) {
                $valor   = $signo * (float) ($fuenteActual[$cc->id]   ?? 0);
                $valorAnt= $signo * (float) ($fuenteAnterior[$cc->id] ?? 0);

                if (abs($valor) < 0.01 && abs($valorAnt) < 0.01) {
                    continue; // omitir cuentas sin movimiento ni saldo
                }

                $variacion = round($valor - $valorAnt, 2);
                $variacionPct = abs($valorAnt) > 0.01
                    ? round((($valor - $valorAnt) / abs($valorAnt)) * 100, 2)
                    : null;

                $filas[] = [
                    'codigo'           => $cc->codigo,
                    'nombre'           => $cc->nombre,
                    'saldo_actual'     => round($valor, 2),
                    'saldo_anterior'   => round($valorAnt, 2),
                    'variacion'        => $variacion,
                    'variacion_pct'    => $variacionPct,
                ];

                $totalActual   += $valor;
                $totalAnterior += $valorAnt;
            }

            $notas[] = [
                'numero'         => $numero,
                'titulo'         => $def['titulo'],
                'cuentas'        => $filas,
                'total_actual'   => round($totalActual, 2),
                'total_anterior' => round($totalAnterior, 2),
                'variacion'      => round($totalActual - $totalAnterior, 2),
                'variacion_pct'  => abs($totalAnterior) > 0.01
                    ? round((($totalActual - $totalAnterior) / abs($totalAnterior)) * 100, 2)
                    : null,
            ];
        }

        return [
            'anio'              => $anio,
            'fecha_corte'       => $fechaCorte,
            'fecha_corte_anterior' => $fechaCorteAnt,
            'notas'             => $notas,
        ];
    }

    /**
     * Saldos acumulados hasta una fecha para cuentas de balance.
     * Retorna mapa cuenta_id → saldo neto débito (signo natural).
     *
     * @return array<string, float>
     */
    private function saldosBalanceHasta(string $hasta): array
    {
        $rows = DB::select('
            SELECT
                al.cuenta_id,
                SUM(al.debito - al.credito) AS saldo
            FROM asiento_items al
            INNER JOIN asientos a            ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc  ON cc.id = al.cuenta_id
            WHERE a.estado = ?
              AND a.fecha  <= ?
              AND LEFT(cc.codigo, 1) IN (?, ?, ?)
            GROUP BY al.cuenta_id
        ', ['aprobado', $hasta, '1', '2', '3']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->cuenta_id] = (float) $r->saldo;
        }
        return $map;
    }

    /**
     * Movimientos del año para cuentas de resultado (clases 4, 5, 6).
     *
     * @return array<string, float>
     */
    private function movimientosResultadoEnAnio(int $anio): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $rows = DB::select('
            SELECT
                al.cuenta_id,
                SUM(al.debito - al.credito) AS saldo
            FROM asiento_items al
            INNER JOIN asientos a            ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc  ON cc.id = al.cuenta_id
            WHERE a.estado = ?
              AND a.fecha  >= ?
              AND a.fecha  <= ?
              AND LEFT(cc.codigo, 1) IN (?, ?, ?)
            GROUP BY al.cuenta_id
        ', ['aprobado', $desde, $hasta, '4', '5', '6']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->cuenta_id] = (float) $r->saldo;
        }
        return $map;
    }

    /**
     * Cuentas (acepta_movimientos=true) que pertenecen a los grupos dados.
     */
    private function cuentasDeGrupos(array $grupos): \Illuminate\Support\Collection
    {
        $query = DB::table('cuentas_contables')
            ->where('activo', true)
            ->where('acepta_movimientos', true);

        $query->where(function ($q) use ($grupos): void {
            foreach ($grupos as $g) {
                $q->orWhereRaw("LEFT(codigo, 2) = ?", [$g]);
            }
        });

        return $query
            ->select('id', 'codigo', 'nombre')
            ->orderBy('codigo')
            ->get();
    }

    private function esGrupoResultado(array $grupos): bool
    {
        // Grupos con código que empieza con 4, 5 o 6 son resultado
        foreach ($grupos as $g) {
            $primer = (string) substr((string) $g, 0, 1);
            if (in_array($primer, ['4', '5', '6'], true)) {
                return true;
            }
        }
        return false;
    }

    private function notaVacia(int $numero, string $titulo): array
    {
        return [
            'numero'         => $numero,
            'titulo'         => $titulo,
            'cuentas'        => [],
            'total_actual'   => 0.0,
            'total_anterior' => 0.0,
            'variacion'      => 0.0,
            'variacion_pct'  => null,
        ];
    }
}
