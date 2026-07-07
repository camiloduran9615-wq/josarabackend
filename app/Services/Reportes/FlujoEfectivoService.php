<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Estado de Flujo de Efectivo (NIC 7) — método indirecto.
 *
 * Estructura:
 *   ACTIVIDADES DE OPERACIÓN
 *     + Utilidad neta del periodo
 *     + Ajustes que no son efectivo (depreciación, provisiones)
 *     +/- Cambios en capital de trabajo (CxC, inventarios, CxP)
 *     = Efectivo neto de operación
 *
 *   ACTIVIDADES DE INVERSIÓN
 *     - Compra de propiedad, planta y equipo (aumento neto clase 15)
 *     + Venta de activos
 *
 *   ACTIVIDADES DE FINANCIACIÓN
 *     + Aportes de socios (aumentos clase 31)
 *     - Pago dividendos (disminuciones clase 37)
 *     + Nuevos préstamos (aumentos clase 21)
 *     - Pago préstamos
 *
 *   = Aumento/(Disminución) en efectivo del periodo
 *   + Efectivo al inicio del periodo (clase 11)
 *   = Efectivo al final del periodo (debería = saldo final clase 11)
 *
 * El reporte termina con una conciliación: el "efectivo al final calculado"
 * debe coincidir con el saldo real de cuentas 11 al cierre del año.
 */
class FlujoEfectivoService
{
    /**
     * Mapeo grupos contables → categoría del EFE.
     * Solo los grupos relevantes; los demás se ignoran.
     */
    private const MAPEO_GRUPOS = [
        // Operación — cambios en capital de trabajo
        '13' => ['categoria' => 'operacion', 'naturaleza' => 'activo',  'rubro' => 'CxC y deudores'],
        '14' => ['categoria' => 'operacion', 'naturaleza' => 'activo',  'rubro' => 'Inventarios'],
        '17' => ['categoria' => 'operacion', 'naturaleza' => 'activo',  'rubro' => 'Diferidos / Otros activos'],
        '22' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Proveedores'],
        '23' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Cuentas por pagar'],
        '24' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Impuestos por pagar'],
        '25' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Obligaciones laborales'],
        '26' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Pasivos estimados / Provisiones'],
        '27' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Diferidos'],
        '28' => ['categoria' => 'operacion', 'naturaleza' => 'pasivo',  'rubro' => 'Otros pasivos'],

        // Inversión — activos fijos
        '15' => ['categoria' => 'inversion', 'naturaleza' => 'activo',  'rubro' => 'Propiedad, Planta y Equipo'],
        '16' => ['categoria' => 'inversion', 'naturaleza' => 'activo',  'rubro' => 'Intangibles'],
        '18' => ['categoria' => 'inversion', 'naturaleza' => 'activo',  'rubro' => 'Inversiones permanentes'],

        // Financiación — patrimonio + obligaciones financieras
        '21' => ['categoria' => 'financiacion', 'naturaleza' => 'pasivo',  'rubro' => 'Obligaciones financieras'],
        '31' => ['categoria' => 'financiacion', 'naturaleza' => 'patrimonio', 'rubro' => 'Aportes de capital'],
        '37' => ['categoria' => 'financiacion', 'naturaleza' => 'patrimonio', 'rubro' => 'Resultados acumulados (dividendos)'],
    ];

    public function generate(int $anio): array
    {
        $inicioAnio = sprintf('%04d-01-01', $anio);
        $finAnio    = sprintf('%04d-12-31', $anio);

        // ── 1. Utilidad neta del periodo (ingresos - costos - gastos) ───────
        // Ingresos clase 4 (crédito - débito), Costos clase 6 (débito - crédito),
        // Gastos clase 5 (débito - crédito).
        $resultados = DB::select('
            SELECT
                LEFT(cc.codigo, 1) AS clase,
                SUM(al.credito - al.debito) AS neto_credito
            FROM asiento_items al
            INNER JOIN asientos a            ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc  ON cc.id = al.cuenta_id
            WHERE a.estado = ?
              AND a.fecha  >= ?
              AND a.fecha  <= ?
              AND LEFT(cc.codigo, 1) IN (?, ?, ?)
            GROUP BY LEFT(cc.codigo, 1)
        ', ['aprobado', $inicioAnio, $finAnio, '4', '5', '6']);

        $ingresos = 0.0;
        $costos = 0.0;
        $gastos = 0.0;
        foreach ($resultados as $r) {
            $monto = (float) $r->neto_credito;
            if ($r->clase === '4') {
                $ingresos = round($monto, 2);
            } elseif ($r->clase === '5') {
                $gastos = round(-$monto, 2);
            } elseif ($r->clase === '6') {
                $costos = round(-$monto, 2);
            }
        }
        $utilidadNeta = round($ingresos - $costos - $gastos, 2);

        // ── 2. Depreciación del año (cuenta 5160 — Depreciación) ────────────
        // Es un AJUSTE: gasto que no es salida de caja, se suma de vuelta.
        $depreciacion = (float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereBetween('a.fecha', [$inicioAnio, $finAnio])
            ->where('cc.codigo', 'like', '5160%')
            ->sum(DB::raw('al.debito - al.credito'));
        $depreciacion = round($depreciacion, 2);

        // ── 3. Cambios en capital de trabajo + otros (por grupo 2 dígitos) ──
        // Saldo inicial = saldo final acumulado al cierre del año anterior.
        // Saldo final   = saldo final acumulado al cierre del año actual.
        // Variación     = final - inicial.
        //
        // Signo del flujo:
        //   ACTIVO:  aumento = USO de caja (negativo)
        //            disminución = LIBERACIÓN de caja (positivo)
        //   PASIVO:  aumento = FUENTE de caja (positivo)
        //            disminución = USO de caja (negativo)

        $variaciones = $this->variacionesPorGrupo($anio);

        $operacion = [
            'utilidad_neta'           => $utilidadNeta,
            'depreciacion'            => $depreciacion,
            'cambios_capital_trabajo' => [],
            'total'                   => 0.0,
        ];
        $inversion = ['movimientos' => [], 'total' => 0.0];
        $financiacion = ['movimientos' => [], 'total' => 0.0];

        $totalOperCapTrabajo = 0.0;

        foreach ($variaciones as $grupo => $variacion) {
            $map = self::MAPEO_GRUPOS[$grupo] ?? null;
            if ($map === null) {
                continue;
            }
            $cambio = (float) $variacion['variacion'];
            if ($cambio === 0.0) {
                continue;
            }
            // Flujo de caja según naturaleza
            $flujo = match ($map['naturaleza']) {
                'activo'     => -$cambio,            // aumento activo → uso de caja
                'pasivo'     => $cambio,             // aumento pasivo → fuente caja
                'patrimonio' => $cambio,             // aumento patrimonio → fuente
                default      => 0.0,
            };
            $flujo = round($flujo, 2);

            $entrada = [
                'grupo'      => $grupo,
                'rubro'      => $map['rubro'],
                'variacion'  => $cambio,
                'flujo_caja' => $flujo,
            ];

            if ($map['categoria'] === 'operacion') {
                $operacion['cambios_capital_trabajo'][] = $entrada;
                $totalOperCapTrabajo += $flujo;
            } elseif ($map['categoria'] === 'inversion') {
                $inversion['movimientos'][] = $entrada;
                $inversion['total'] += $flujo;
            } elseif ($map['categoria'] === 'financiacion') {
                $financiacion['movimientos'][] = $entrada;
                $financiacion['total'] += $flujo;
            }
        }

        $operacion['total'] = round($utilidadNeta + $depreciacion + $totalOperCapTrabajo, 2);
        $inversion['total'] = round($inversion['total'], 2);
        $financiacion['total'] = round($financiacion['total'], 2);

        // ── 4. Efectivo inicial y final (clase 11) ──────────────────────────
        $efectivoInicial = $this->efectivoAlCierreDel($anio - 1);
        $efectivoFinal   = $this->efectivoAlCierreDel($anio);

        $aumentoEfectivo = round($operacion['total'] + $inversion['total'] + $financiacion['total'], 2);
        $efectivoCalculado = round($efectivoInicial + $aumentoEfectivo, 2);

        // Diferencia entre cálculo y saldo real (debería ser 0 si la contabilidad es perfecta)
        $diferencia = round($efectivoFinal - $efectivoCalculado, 2);

        return [
            'anio'         => $anio,
            'fecha_inicio' => $inicioAnio,
            'fecha_fin'    => $finAnio,

            'operacion'    => $operacion,
            'inversion'    => $inversion,
            'financiacion' => $financiacion,

            'aumento_efectivo'   => $aumentoEfectivo,
            'efectivo_inicial'   => $efectivoInicial,
            'efectivo_final_calculado' => $efectivoCalculado,
            'efectivo_final_real'      => $efectivoFinal,
            'diferencia_conciliacion'  => $diferencia,

            'totales_pyg' => [
                'ingresos'      => $ingresos,
                'costos'        => $costos,
                'gastos'        => $gastos,
                'utilidad_neta' => $utilidadNeta,
            ],
        ];
    }

    /**
     * Variación de cada grupo contable (LEFT(codigo,2)) en el año.
     *
     * @return array<string, array{variacion: float, saldo_inicial: float, saldo_final: float}>
     */
    private function variacionesPorGrupo(int $anio): array
    {
        $inicioAnio = sprintf('%04d-01-01', $anio);
        $finAnio    = sprintf('%04d-12-31', $anio);

        // Saldo inicial por grupo (saldo final acumulado al cierre del año anterior)
        $inicialesRaw = DB::select('
            SELECT
                LEFT(cc.codigo, 2) AS grupo,
                SUM(cs.saldo_final_debito - cs.saldo_final_credito) AS saldo_neto_debito
            FROM cuenta_saldos cs
            INNER JOIN periodos_contables p ON p.id = cs.periodo_id
            INNER JOIN cuentas_contables cc ON cc.id = cs.cuenta_contable_id
            WHERE p.fecha_inicio < ?
            GROUP BY LEFT(cc.codigo, 2)
        ', [$inicioAnio]);

        $inicialPorGrupo = [];
        foreach ($inicialesRaw as $r) {
            $inicialPorGrupo[$r->grupo] = round((float) $r->saldo_neto_debito, 2);
        }

        // Saldo final = inicial + movimientos del año (debito - credito)
        $movsRaw = DB::select('
            SELECT
                LEFT(cc.codigo, 2) AS grupo,
                SUM(al.debito - al.credito) AS movimiento_neto
            FROM asiento_items al
            INNER JOIN asientos a            ON a.id  = al.asiento_id
            INNER JOIN cuentas_contables cc  ON cc.id = al.cuenta_id
            WHERE a.estado = ?
              AND a.fecha  >= ?
              AND a.fecha  <= ?
            GROUP BY LEFT(cc.codigo, 2)
        ', ['aprobado', $inicioAnio, $finAnio]);

        $movsPorGrupo = [];
        foreach ($movsRaw as $r) {
            $movsPorGrupo[$r->grupo] = round((float) $r->movimiento_neto, 2);
        }

        $grupos = array_unique(array_merge(array_keys($inicialPorGrupo), array_keys($movsPorGrupo)));

        $resultado = [];
        foreach ($grupos as $grupo) {
            $inicial = $inicialPorGrupo[$grupo] ?? 0.0;
            $mov     = $movsPorGrupo[$grupo] ?? 0.0;
            $final   = round($inicial + $mov, 2);

            // Para grupos ACTIVO: saldo natural = débito (positivo)
            // Para grupos PASIVO/PATRIMONIO: el saldo neto débito es negativo;
            // lo invertimos para que la variación sea expresada en valores positivos
            // cuando el saldo natural (crédito) aumenta.
            $primera = (int) substr((string) $grupo, 0, 1);
            $signo = ($primera >= 2) ? -1 : 1;  // pasivos/patrimonio: invertir
            $inicialAjustado = $signo * $inicial;
            $finalAjustado   = $signo * $final;
            $variacion       = round($finalAjustado - $inicialAjustado, 2);

            $resultado[$grupo] = [
                'saldo_inicial' => $inicialAjustado,
                'saldo_final'   => $finalAjustado,
                'variacion'     => $variacion,
            ];
        }

        return $resultado;
    }

    /**
     * Saldo total de cuentas de efectivo (clase 11) al cierre de un año.
     */
    private function efectivoAlCierreDel(int $anio): float
    {
        $hasta = sprintf('%04d-12-31', $anio);

        // Saldo final = sum(saldo_final_debito - saldo_final_credito) de todas
        // las filas de cuenta_saldos con periodo cuyo fecha_inicio <= hasta,
        // para cuentas clase 1 grupo 11.
        $row = DB::table('cuenta_saldos as cs')
            ->join('periodos_contables as p', 'p.id', '=', 'cs.periodo_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'cs.cuenta_contable_id')
            ->where('p.fecha_inicio', '<=', $hasta)
            ->whereRaw("LEFT(cc.codigo, 2) = '11'")
            ->selectRaw('SUM(cs.saldo_final_debito - cs.saldo_final_credito) AS efectivo')
            ->first();

        return round((float) ($row->efectivo ?? 0), 2);
    }
}
