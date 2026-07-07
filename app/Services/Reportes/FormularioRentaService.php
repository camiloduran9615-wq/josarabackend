<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Formulario 110 — Declaración de Renta y Complementario
 * (Personas Jurídicas y Asimiladas).
 *
 * Genera los renglones a partir del PUC del tenant:
 *   • Ingresos (clase 4)
 *   • Costos    (clase 6)
 *   • Gastos / deducciones (clase 5)
 *   • Renta líquida ordinaria = ingresos netos − costos − deducciones
 *   • Impuesto = renta líquida gravable × tarifa
 *   • Retenciones practicadas (1355) → restan al saldo a cargo
 *
 * Marco normativo:
 *   Estatuto Tributario, arts. 26, 240 (tarifa), 365.
 *   Ley 2277 de 2022 — tarifa general personas jurídicas 35%.
 *
 * Nota: este reporte es una BASE DE PRELLENADO. El contador debe
 * ajustar manualmente: rentas exentas, compensaciones de pérdidas,
 * descuentos tributarios, anticipos y rentas presuntivas.
 */
class FormularioRentaService
{
    public const TARIFA_GENERAL  = 0.35;  // 35% personas jurídicas (vigente 2026)
    public const TARIFA_GANANCIA = 0.15;  // 15% ganancias ocasionales

    public function generate(int $anio, ?float $tarifa = null): array
    {
        $tarifa = $tarifa ?? self::TARIFA_GENERAL;

        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        // ── Movimientos del año por código de cuenta (4 dígitos) ────────────
        // Excluimos asientos de CIERRE (tipo_comprobante = 'CI') porque cancelan
        // las cuentas de resultado en su naturaleza opuesta — si los incluimos
        // tras un cierre anual, los ingresos/gastos quedan en cero y el F110
        // sale vacío. El F110 reporta la OPERACIÓN del año, no el efecto del cierre.
        $movimientos = DB::select("
            SELECT
                cc.codigo,
                cc.nombre,
                LEFT(cc.codigo, 1) AS clase,
                LEFT(cc.codigo, 2) AS grupo,
                LEFT(cc.codigo, 4) AS cuenta4,
                SUM(al.debito)  AS deb,
                SUM(al.credito) AS cred
            FROM asiento_items al
            INNER JOIN asientos a            ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc  ON cc.id = al.cuenta_id
            WHERE a.estado = ?
              AND a.fecha >= ?
              AND a.fecha <= ?
              AND COALESCE(a.tipo_comprobante, '') != 'CI'
            GROUP BY cc.codigo, cc.nombre, LEFT(cc.codigo, 1), LEFT(cc.codigo, 2), LEFT(cc.codigo, 4)
            ORDER BY cc.codigo
        ", ['aprobado', $desde, $hasta]);

        // Agrupador: sumar por grupo (2 dígitos) y cuenta (4 dígitos)
        $porGrupo = [];   // grupo 2d → ['ingresos'|'gastos'|'costos'|'activos' => neto]
        $detalle = [];    // codigo => ['nombre', 'monto']

        foreach ($movimientos as $m) {
            $clase   = (string) $m->clase;
            $grupo   = (string) $m->grupo;
            $deb     = (float) $m->deb;
            $cred    = (float) $m->cred;

            // Naturaleza:
            //  Clase 4 (ingresos): neto = crédito − débito (saldo natural crédito)
            //  Clase 5 / 6 (gastos / costos): neto = débito − crédito
            //  Clase 1 (anticipos 1355): neto = débito − crédito (cargos al activo)
            if ($clase === '4') {
                $neto = round($cred - $deb, 2);
            } else {
                $neto = round($deb - $cred, 2);
            }

            if (abs($neto) < 0.01) {
                continue;
            }

            $porGrupo[$grupo] = ($porGrupo[$grupo] ?? 0) + $neto;
            $detalle[(string) $m->codigo] = [
                'nombre' => (string) $m->nombre,
                'monto'  => $neto,
            ];
        }

        $g = fn (string $grp) => round((float) ($porGrupo[$grp] ?? 0), 2);

        // ── INGRESOS (clase 4) ──────────────────────────────────────────────
        // 41  Operacionales (ventas + servicios)
        // 42  No operacionales (ingresos financieros, otros)
        // 4175 Devoluciones, rebajas y descuentos (signo negativo).
        $ingresosOperacionales = $g('41');
        $ingresosNoOperacionales = $g('42');

        // Devoluciones (4175): si el grupo 41 ya las incluye, no las restamos dos veces.
        // Aquí asumimos que están dentro de "operacionales" y el contador puede ver el desglose.
        $totalIngresosBrutos = round($ingresosOperacionales + $ingresosNoOperacionales, 2);
        $totalIngresosNetos  = $totalIngresosBrutos; // el contador resta no constitutivos manualmente

        // ── COSTOS (clase 6) ─────────────────────────────────────────────────
        // 61 Costo de ventas
        // 62 Otros costos
        $costoVentas = $g('61');
        $otrosCostos = $g('62') + $g('63') + $g('64') + $g('65');
        $totalCostos = round($costoVentas + $otrosCostos, 2);

        // ── DEDUCCIONES (clase 5) ───────────────────────────────────────────
        // 51 Operacionales de administración
        // 52 Operacionales de ventas
        // 53 No operacionales (financieros)
        // 54 Impuesto de renta (no se deduce de sí mismo, lo excluimos)
        $gastosAdmin   = $g('51');
        $gastosVentas  = $g('52');
        $gastosNoOper  = $g('53');
        // Importante: 54 = Impuesto de renta → NO se deduce.

        $totalDeducciones = round($gastosAdmin + $gastosVentas + $gastosNoOper, 2);

        // ── RENTA LÍQUIDA ORDINARIA ─────────────────────────────────────────
        $rentaLiquidaOrdinaria = round($totalIngresosNetos - $totalCostos - $totalDeducciones, 2);

        // Renta líquida gravable (sin compensaciones ni renta exenta — el contador ajusta)
        $rentaLiquidaGravable = max(0.0, $rentaLiquidaOrdinaria);

        // ── LIQUIDACIÓN DEL IMPUESTO ────────────────────────────────────────
        $impuestoSobreRenta = round($rentaLiquidaGravable * $tarifa, 2);
        $totalImpuestoCargo = $impuestoSobreRenta;

        // ── ANTICIPOS / RETENCIONES PRACTICADAS (1355) ──────────────────────
        // Es el saldo deudor de 1355xx al cierre del año: lo que clientes nos
        // retuvieron y vamos a aplicar contra el impuesto.
        $retencionesPracticadas = (float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->whereRaw("COALESCE(a.tipo_comprobante, '') != 'CI'")
            ->where('cc.codigo', 'like', '1355%')
            ->sum(DB::raw('al.debito - al.credito'));
        $retencionesPracticadas = round($retencionesPracticadas, 2);

        // Saldo a pagar (positivo) o a favor (negativo)
        $saldoAPagar = round($totalImpuestoCargo - $retencionesPracticadas, 2);

        // ── RENGLONES (estructura DIAN simplificada) ────────────────────────
        $renglones = [
            // Ingresos
            32 => ['titulo' => 'Ingresos brutos de actividades ordinarias',     'valor' => $ingresosOperacionales],
            33 => ['titulo' => 'Ingresos no operacionales',                     'valor' => $ingresosNoOperacionales],
            38 => ['titulo' => 'Total ingresos brutos',                         'valor' => $totalIngresosBrutos,   'bold' => true],
            41 => ['titulo' => 'Total ingresos netos',                          'valor' => $totalIngresosNetos,    'bold' => true],

            // Costos
            42 => ['titulo' => 'Costo de ventas y de servicios',                'valor' => $costoVentas],
            43 => ['titulo' => 'Otros costos',                                  'valor' => $otrosCostos],
            44 => ['titulo' => 'Total costos',                                  'valor' => $totalCostos,            'bold' => true],

            // Deducciones
            45 => ['titulo' => 'Gastos operacionales de administración',        'valor' => $gastosAdmin],
            46 => ['titulo' => 'Gastos operacionales de ventas',                'valor' => $gastosVentas],
            48 => ['titulo' => 'Otras deducciones (financieros / no oper.)',    'valor' => $gastosNoOper],
            49 => ['titulo' => 'Total deducciones',                             'valor' => $totalDeducciones,       'bold' => true],

            // Renta líquida
            50 => ['titulo' => 'Renta líquida ordinaria del ejercicio',         'valor' => $rentaLiquidaOrdinaria,  'bold' => true],
            54 => ['titulo' => 'Renta líquida gravable',                        'valor' => $rentaLiquidaGravable,   'bold' => true],

            // Liquidación
            57 => ['titulo' => sprintf('Impuesto sobre renta líquida gravable (%s%%)', $tarifa * 100), 'valor' => $impuestoSobreRenta],
            61 => ['titulo' => 'Total impuesto a cargo',                        'valor' => $totalImpuestoCargo,     'bold' => true],
            65 => ['titulo' => 'Retenciones en la fuente practicadas',          'valor' => $retencionesPracticadas],
            67 => ['titulo' => $saldoAPagar >= 0 ? 'Saldo a pagar' : 'Saldo a favor', 'valor' => abs($saldoAPagar), 'bold' => true],
        ];

        // ── DESGLOSE por cuenta (para que el contador audite) ───────────────
        ksort($detalle);

        return [
            'anio'            => $anio,
            'fecha_inicio'    => $desde,
            'fecha_fin'       => $hasta,
            'tarifa'          => $tarifa,
            'renglones'       => $renglones,

            'resumen' => [
                'ingresos_operacionales'    => $ingresosOperacionales,
                'ingresos_no_operacionales' => $ingresosNoOperacionales,
                'total_ingresos_brutos'     => $totalIngresosBrutos,
                'total_ingresos_netos'      => $totalIngresosNetos,
                'costo_ventas'              => $costoVentas,
                'otros_costos'              => $otrosCostos,
                'total_costos'              => $totalCostos,
                'gastos_administracion'     => $gastosAdmin,
                'gastos_ventas'             => $gastosVentas,
                'gastos_no_operacionales'   => $gastosNoOper,
                'total_deducciones'         => $totalDeducciones,
                'renta_liquida_ordinaria'   => $rentaLiquidaOrdinaria,
                'renta_liquida_gravable'    => $rentaLiquidaGravable,
                'impuesto_sobre_renta'      => $impuestoSobreRenta,
                'total_impuesto_cargo'      => $totalImpuestoCargo,
                'retenciones_practicadas'   => $retencionesPracticadas,
                'saldo_a_pagar'             => max(0.0, $saldoAPagar),
                'saldo_a_favor'             => max(0.0, -$saldoAPagar),
            ],

            'detalle_por_cuenta' => array_map(
                fn ($cod, $d) => ['codigo' => $cod, 'nombre' => $d['nombre'], 'monto' => $d['monto']],
                array_keys($detalle),
                $detalle,
            ),

            'advertencia' => 'Este reporte es una BASE DE PRELLENADO desde la contabilidad. ' .
                'El contador debe ajustar manualmente: rentas exentas (art. 235-2 ET), ' .
                'compensaciones de pérdidas (art. 147 ET), descuentos tributarios (art. 254-259 ET), ' .
                'anticipo año siguiente (art. 807 ET) y renta presuntiva (art. 188 ET) si aplica.',
        ];
    }
}
