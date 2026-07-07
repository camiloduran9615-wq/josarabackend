<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Estado de Cambios en el Patrimonio (NIC 1).
 *
 * Muestra para cada cuenta de patrimonio (clase 3):
 *   - Saldo inicial del año fiscal (acumulado de periodos previos)
 *   - Aumentos (movimientos crédito del año, naturaleza crédito)
 *   - Disminuciones (movimientos débito del año)
 *   - Saldo final
 *
 * Categorías típicas (Decreto 2650 / NIIF):
 *   31 Capital social        — aportes de socios
 *   33 Reservas              — legales, estatutarias, ocasionales
 *   36 Resultados del ejercicio
 *   37 Resultados acumulados (utilidades retenidas)
 *   38 Superávit de capital  — valorizaciones, donaciones
 *
 * El reporte sirve para:
 *   - Acta de Asamblea de Accionistas (decisiones sobre utilidades)
 *   - Supersociedades (reporte anual)
 *   - Anexos de declaración de renta (capital empresarial)
 */
class EstadoCambiosPatrimonioService
{
    public function generate(int $anio): array
    {
        $inicioAnio = sprintf('%04d-01-01', $anio);
        $finAnio    = sprintf('%04d-12-31', $anio);

        // ── Saldo inicial del año: acumulado al cierre del año anterior ─────
        $iniciales = DB::select('
            SELECT
                cs.cuenta_contable_id,
                SUM(cs.saldo_final_debito)  AS si_d,
                SUM(cs.saldo_final_credito) AS si_c
            FROM cuenta_saldos cs
            INNER JOIN periodos_contables p ON p.id = cs.periodo_id
            INNER JOIN cuentas_contables cc ON cc.id = cs.cuenta_contable_id
            WHERE cc.clase = 3
              AND p.fecha_inicio < ?
            GROUP BY cs.cuenta_contable_id
        ', [$inicioAnio]);

        $iniciaPorCuenta = [];
        foreach ($iniciales as $r) {
            $neto = (float) $r->si_c - (float) $r->si_d;
            $iniciaPorCuenta[$r->cuenta_contable_id] = round($neto, 2);
        }

        // ── Movimientos del año (clase 3) ───────────────────────────────────
        $movs = DB::select('
            SELECT
                al.cuenta_id                AS cuenta_id,
                SUM(al.debito)              AS deb,
                SUM(al.credito)             AS cred
            FROM asiento_items al
            INNER JOIN asientos a   ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc ON cc.id = al.cuenta_id
            WHERE cc.clase  = 3
              AND a.estado  = ?
              AND a.fecha  >= ?
              AND a.fecha  <= ?
            GROUP BY al.cuenta_id
        ', ['aprobado', $inicioAnio, $finAnio]);

        $movPorCuenta = [];
        foreach ($movs as $r) {
            $movPorCuenta[$r->cuenta_id] = [
                'deb'  => round((float) $r->deb, 2),
                'cred' => round((float) $r->cred, 2),
            ];
        }

        // ── Listar cuentas con saldo inicial != 0 OR con movimiento ─────────
        $cuentaIds = array_unique(array_merge(
            array_keys($iniciaPorCuenta),
            array_keys($movPorCuenta),
        ));

        if ($cuentaIds === []) {
            return $this->emptyReport($anio, $inicioAnio, $finAnio);
        }

        // Cargar metadata de las cuentas
        $cuentas = DB::table('cuentas_contables')
            ->whereIn('id', $cuentaIds)
            ->select('id', 'codigo', 'nombre', 'clase', 'naturaleza')
            ->orderBy('codigo')
            ->get()
            ->keyBy('id');

        // ── Categorizar por grupo (2 primeros dígitos del código) ───────────
        $categorias = [
            '31' => ['nombre' => 'Capital Social',           'cuentas' => []],
            '32' => ['nombre' => 'Superávit de Capital',     'cuentas' => []],
            '33' => ['nombre' => 'Reservas',                 'cuentas' => []],
            '34' => ['nombre' => 'Revalorización del Patrimonio', 'cuentas' => []],
            '35' => ['nombre' => 'Dividendos / Participaciones',  'cuentas' => []],
            '36' => ['nombre' => 'Resultados del Ejercicio', 'cuentas' => []],
            '37' => ['nombre' => 'Resultados de Ejercicios Anteriores', 'cuentas' => []],
            '38' => ['nombre' => 'Superávit por Valorización', 'cuentas' => []],
        ];
        $otrasCuentas = []; // cuentas clase 3 que no caen en 31..38 (raro pero defensivo)

        $totalSi = 0.0;
        $totalAumentos = 0.0;
        $totalDisminuciones = 0.0;
        $totalSf = 0.0;

        foreach ($cuentaIds as $cid) {
            $cc = $cuentas->get($cid);
            if ($cc === null) {
                continue;
            }
            $si = (float) ($iniciaPorCuenta[$cid] ?? 0);
            $deb  = (float) ($movPorCuenta[$cid]['deb']  ?? 0);
            $cred = (float) ($movPorCuenta[$cid]['cred'] ?? 0);

            // Naturaleza crédito (patrimonio) → aumentos = créditos, disminuciones = débitos
            $aumentos      = $cred;
            $disminuciones = $deb;
            $sf            = round($si + $aumentos - $disminuciones, 2);

            $fila = [
                'codigo'        => $cc->codigo,
                'nombre'        => $cc->nombre,
                'saldo_inicial' => round($si, 2),
                'aumentos'      => round($aumentos, 2),
                'disminuciones' => round($disminuciones, 2),
                'saldo_final'   => $sf,
            ];

            $grupo = substr((string) $cc->codigo, 0, 2);
            if (isset($categorias[$grupo])) {
                $categorias[$grupo]['cuentas'][] = $fila;
            } else {
                $otrasCuentas[] = $fila;
            }

            $totalSi             += $si;
            $totalAumentos       += $aumentos;
            $totalDisminuciones  += $disminuciones;
            $totalSf             += $sf;
        }

        // ── Inyectar Resultado del Ejercicio PROVISIONAL ───────────────────
        // Mientras no se haga el cierre formal (clase 4-5-6-7 → 5905 → 36),
        // la utilidad/pérdida del periodo vive en clases 4-5-6-7 y nunca aparecería
        // en el ECP. NIC 1.106 exige reflejarla. Si la categoría 36 está vacía,
        // calculamos y agregamos una línea virtual con el resultado del año.
        if ($categorias['36']['cuentas'] === []) {
            $resultado = $this->calcularResultadoProvisional($inicioAnio, $finAnio);
            if (abs($resultado) > 0.01) {
                // Saldo final ≥ 0 → utilidad (crédito en 36) ; < 0 → pérdida (débito)
                $aumentos      = $resultado >= 0 ? round($resultado, 2)        : 0.0;
                $disminuciones = $resultado < 0  ? round(abs($resultado), 2)   : 0.0;
                $sf            = round($aumentos - $disminuciones, 2);
                $categorias['36']['cuentas'][] = [
                    'codigo'        => '3605',
                    'nombre'        => $resultado >= 0
                        ? 'Utilidad del Ejercicio (provisional)'
                        : 'Pérdida del Ejercicio (provisional)',
                    'saldo_inicial' => 0.0,
                    'aumentos'      => $aumentos,
                    'disminuciones' => $disminuciones,
                    'saldo_final'   => $sf,
                ];
                $totalAumentos      += $aumentos;
                $totalDisminuciones += $disminuciones;
                $totalSf            += $sf;
            }
        }

        // Agregar totales por categoría y filtrar las vacías
        $categoriasOut = [];
        foreach ($categorias as $codigo => $cat) {
            if ($cat['cuentas'] === []) {
                continue;
            }
            $sumSi  = array_sum(array_column($cat['cuentas'], 'saldo_inicial'));
            $sumAum = array_sum(array_column($cat['cuentas'], 'aumentos'));
            $sumDis = array_sum(array_column($cat['cuentas'], 'disminuciones'));
            $sumSf  = array_sum(array_column($cat['cuentas'], 'saldo_final'));

            $categoriasOut[] = [
                'codigo'        => $codigo,
                'nombre'        => $cat['nombre'],
                'cuentas'       => $cat['cuentas'],
                'saldo_inicial' => round($sumSi, 2),
                'aumentos'      => round($sumAum, 2),
                'disminuciones' => round($sumDis, 2),
                'saldo_final'   => round($sumSf, 2),
            ];
        }

        if ($otrasCuentas !== []) {
            $sumSi  = array_sum(array_column($otrasCuentas, 'saldo_inicial'));
            $sumAum = array_sum(array_column($otrasCuentas, 'aumentos'));
            $sumDis = array_sum(array_column($otrasCuentas, 'disminuciones'));
            $sumSf  = array_sum(array_column($otrasCuentas, 'saldo_final'));
            $categoriasOut[] = [
                'codigo'        => 'otras',
                'nombre'        => 'Otras Cuentas de Patrimonio',
                'cuentas'       => $otrasCuentas,
                'saldo_inicial' => round($sumSi, 2),
                'aumentos'      => round($sumAum, 2),
                'disminuciones' => round($sumDis, 2),
                'saldo_final'   => round($sumSf, 2),
            ];
        }

        return [
            'anio'            => $anio,
            'fecha_inicio'    => $inicioAnio,
            'fecha_fin'       => $finAnio,
            'categorias'      => $categoriasOut,
            'totales'         => [
                'saldo_inicial' => round($totalSi, 2),
                'aumentos'      => round($totalAumentos, 2),
                'disminuciones' => round($totalDisminuciones, 2),
                'saldo_final'   => round($totalSf, 2),
            ],
        ];
    }

    /**
     * Calcula la utilidad/pérdida del periodo a partir de clases 4-5-6-7
     * (mismo cómputo que Estado de Resultados, en moneda funcional).
     *
     * Convenio de signo:
     *   resultado > 0  → Utilidad   (credit-natured)
     *   resultado < 0  → Pérdida    (debit-natured)
     */
    private function calcularResultadoProvisional(string $inicio, string $fin): float
    {
        $rows = DB::select('
            SELECT cc.clase, SUM(al.debito) AS deb, SUM(al.credito) AS cred
            FROM asiento_items al
            INNER JOIN asientos a   ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc ON cc.id = al.cuenta_id
            WHERE cc.clase IN (4, 5, 6, 7)
              AND a.estado  = ?
              AND a.fecha  >= ?
              AND a.fecha  <= ?
            GROUP BY cc.clase
        ', ['aprobado', $inicio, $fin]);

        $ingresos = 0.0; $gastos = 0.0; $costos = 0.0; $otros = 0.0;
        foreach ($rows as $r) {
            $deb  = (float) $r->deb;
            $cred = (float) $r->cred;
            match ((int) $r->clase) {
                4 => $ingresos = $cred - $deb,
                5 => $gastos   = $deb  - $cred,
                6 => $costos   = $deb  - $cred,
                7 => $otros    = $deb  - $cred,
                default => null,
            };
        }
        return round($ingresos - $costos - $gastos - $otros, 2);
    }

    private function emptyReport(int $anio, string $inicio, string $fin): array
    {
        return [
            'anio'         => $anio,
            'fecha_inicio' => $inicio,
            'fecha_fin'    => $fin,
            'categorias'   => [],
            'totales'      => [
                'saldo_inicial' => 0.0,
                'aumentos'      => 0.0,
                'disminuciones' => 0.0,
                'saldo_final'   => 0.0,
            ],
        ];
    }
}
