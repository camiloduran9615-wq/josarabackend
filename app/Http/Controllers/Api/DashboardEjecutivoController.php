<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /{tenant}/dashboard-ejecutivo
 *
 * KPIs gerenciales avanzados:
 *   • YTD (Year-To-Date) vs mismo periodo año anterior
 *   • Aging de cartera (corriente / 1-30 / 31-60 / 61-90 / +90)
 *   • Top 5 clientes por ventas YTD
 *   • Liquidez (saldos de bancos + caja, clases 11)
 *   • Indicadores financieros: margen bruto %, margen neto %, días de cartera
 *   • Impuestos pendientes (IVA por pagar 2408, retefuente 2365)
 *   • Alertas operacionales
 */
class DashboardEjecutivoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $hoy        = Carbon::today();
        $ytdInicio  = $hoy->copy()->startOfYear();
        $anteriorIn = $hoy->copy()->subYear()->startOfYear();
        $anteriorFn = $hoy->copy()->subYear();

        $t0 = microtime(true);

        // ── 1. YTD vs Año anterior ──────────────────────────────────────────
        $ingresosYtd  = $this->movClase('4', $ytdInicio, $hoy);
        $costosYtd    = $this->movClase('6', $ytdInicio, $hoy);
        $gastosYtd    = $this->movClase('5', $ytdInicio, $hoy);
        $utilidadYtd  = round($ingresosYtd - $costosYtd - $gastosYtd, 2);

        $ingresosAnt  = $this->movClase('4', $anteriorIn, $anteriorFn);
        $costosAnt    = $this->movClase('6', $anteriorIn, $anteriorFn);
        $gastosAnt    = $this->movClase('5', $anteriorIn, $anteriorFn);
        $utilidadAnt  = round($ingresosAnt - $costosAnt - $gastosAnt, 2);

        // ── 2. Aging de cartera ─────────────────────────────────────────────
        $aging = $this->agingCartera($hoy);

        // ── 3. Top 5 clientes YTD ───────────────────────────────────────────
        $topClientes = DB::table('facturas as f')
            ->leftJoin('terceros as t', 't.id', '=', 'f.tercero_id')
            ->whereBetween('f.fecha_emision', [$ytdInicio->toDateString(), $hoy->toDateString()])
            ->whereIn('f.estado', ['validado', 'enviado', 'aprobado'])
            ->groupBy('f.tercero_id', 't.razon_social', 't.identificacion')
            ->selectRaw('
                f.tercero_id,
                t.razon_social AS nombre,
                t.identificacion AS documento,
                COUNT(*)       AS facturas,
                COALESCE(SUM(f.valor_total), 0) AS total
            ')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'tercero_id' => $r->tercero_id,
                'nombre'     => $r->nombre ?? '— sin tercero —',
                'documento'  => $r->documento,
                'facturas'   => (int)   $r->facturas,
                'total'      => (float) $r->total,
            ])
            ->toArray();

        // ── 4. Liquidez (caja + bancos = clase 11) ──────────────────────────
        $liquidez = $this->saldoClase('11');

        // Desglose por tipo: caja (1105) vs bancos (1110)
        $detalleLiquidez = DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereRaw("LEFT(cc.codigo, 2) = '11'")
            ->selectRaw("
                LEFT(cc.codigo, 4) AS cuenta,
                MIN(cc.nombre)     AS nombre,
                COALESCE(SUM(al.debito - al.credito), 0) AS saldo
            ")
            ->groupBy(DB::raw("LEFT(cc.codigo, 4)"))
            ->having(DB::raw('COALESCE(SUM(al.debito - al.credito), 0)'), '!=', 0)
            ->orderBy('cuenta')
            ->get()
            ->map(fn ($r) => [
                'cuenta' => $r->cuenta,
                'nombre' => $r->nombre,
                'saldo'  => round((float) $r->saldo, 2),
            ])
            ->toArray();

        // ── 5. Indicadores financieros ──────────────────────────────────────
        $margenBruto = $ingresosYtd > 0
            ? round((($ingresosYtd - $costosYtd) / $ingresosYtd) * 100, 2)
            : 0.0;
        $margenNeto  = $ingresosYtd > 0
            ? round(($utilidadYtd / $ingresosYtd) * 100, 2)
            : 0.0;

        // Días promedio de cartera = (CxC / Ventas YTD) × días_transcurridos
        $diasTranscurridos = $ytdInicio->diffInDays($hoy) + 1;
        $cxc = (float) ($aging['total'] ?? 0);
        $diasCartera = $ingresosYtd > 0
            ? round(($cxc / $ingresosYtd) * $diasTranscurridos, 1)
            : 0.0;

        // ── 6. Impuestos pendientes (DIAN) ──────────────────────────────────
        // IVA: solo subcuentas acreedoras (240801/240805/etc.) — excluir 240810
        // descontable (deudor) que reduciría el saldo equivocadamente.
        $ivaPorPagar     = $this->saldoCuentaCredito('2408%');
        $retefuenteDian  = $this->saldoCuentaCredito('2365%'); // todas las retefuente practicadas
        $reteicaDian     = $this->saldoCuentaCredito('2368%'); // ReteICA
        $impuestosTotal  = round($ivaPorPagar + $retefuenteDian + $reteicaDian, 2);

        // ── 7. Alertas operacionales ────────────────────────────────────────
        $alertas = $this->alertas($hoy);

        $ms = round((microtime(true) - $t0) * 1000, 1);

        return response()->json([
            'success' => true,
            'data'    => [
                'fecha_corte' => $hoy->toDateString(),
                'periodo' => [
                    'inicio_ytd' => $ytdInicio->toDateString(),
                    'fin_ytd'    => $hoy->toDateString(),
                    'dias'       => $diasTranscurridos,
                ],

                'ytd' => [
                    'actual' => [
                        'ingresos' => $ingresosYtd,
                        'costos'   => $costosYtd,
                        'gastos'   => $gastosYtd,
                        'utilidad' => $utilidadYtd,
                    ],
                    'anterior' => [
                        'ingresos' => $ingresosAnt,
                        'costos'   => $costosAnt,
                        'gastos'   => $gastosAnt,
                        'utilidad' => $utilidadAnt,
                    ],
                    'variacion' => [
                        'ingresos' => $this->pct($ingresosAnt, $ingresosYtd),
                        'utilidad' => $this->pct($utilidadAnt, $utilidadYtd),
                    ],
                ],

                'aging_cartera' => $aging,
                'top_clientes'  => $topClientes,

                'liquidez' => [
                    'total'    => $liquidez,
                    'detalle'  => $detalleLiquidez,
                ],

                'indicadores' => [
                    'margen_bruto_pct' => $margenBruto,
                    'margen_neto_pct'  => $margenNeto,
                    'dias_cartera'     => $diasCartera,
                ],

                'impuestos_pendientes' => [
                    'iva_por_pagar'      => $ivaPorPagar,
                    'retefuente_por_pagar' => $retefuenteDian,
                    'reteica_por_pagar'   => $reteicaDian,
                    'total'              => $impuestosTotal,
                ],

                'alertas' => $alertas,
                'meta'    => ['ms' => $ms],
            ],
        ]);
    }

    /**
     * Suma de movimientos del año para una clase (4/5/6) — saldo natural.
     */
    private function movClase(string $clase, Carbon $desde, Carbon $hasta): float
    {
        $expr = $clase === '4'
            ? 'al.credito - al.debito'   // ingresos: crédito natural
            : 'al.debito - al.credito';  // costos/gastos: débito natural

        return round((float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereBetween('a.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->whereRaw("LEFT(cc.codigo, 1) = ?", [$clase])
            ->sum(DB::raw($expr)), 2);
    }

    /**
     * Saldo total acumulado de cuentas que empiezan con un prefijo (clase/grupo).
     * Suma directamente desde asientos aprobados para reflejar saldo al día.
     */
    private function saldoClase(string $prefijo): float
    {
        return round((float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereRaw("LEFT(cc.codigo, ?) = ?", [strlen($prefijo), $prefijo])
            ->sum(DB::raw('al.debito - al.credito')), 2);
    }

    /**
     * Saldo de cuentas con código LIKE patrón al día de hoy.
     * Sumamos directamente desde asiento_items (asientos aprobados) para
     * no depender de la materialización de cuenta_saldos por periodo.
     * Devuelve valor positivo para naturaleza crédito (impuestos).
     */
    private function saldoCuentaLike(string $pattern): float
    {
        $saldo = (float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->where('cc.codigo', 'like', $pattern)
            ->sum(DB::raw('al.credito - al.debito'));
        return round(max(0.0, $saldo), 2);
    }

    /**
     * Saldo acreedor neto solo de cuentas de naturaleza 'credito' que matchean
     * el patrón. Útil para impuestos por pagar: ignora subcuentas deudoras
     * como IVA Descontable (240810) que no son obligación con el fisco.
     */
    private function saldoCuentaCredito(string $pattern): float
    {
        $saldo = (float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->where('cc.codigo', 'like', $pattern)
            ->where('cc.naturaleza', 'credito')
            ->sum(DB::raw('al.credito - al.debito'));
        return round(max(0.0, $saldo), 2);
    }

    /**
     * Aging de cartera basado en facturas con valor_total y payment_due_date.
     *
     * Nota: usa valor_total como saldo (no descuenta pagos parciales —
     * la aplicación recibos↔facturas vive en el campo JSON facturas_aplicadas
     * y no soporta saldos parciales por factura en el modelo actual).
     * Por simplicidad, asume saldo = valor_total para facturas no anuladas.
     */
    private function agingCartera(Carbon $hoy): array
    {
        $facturas = DB::table('facturas')
            ->whereIn('estado', ['validado', 'enviado', 'aprobado'])
            ->select('id', 'valor_total', 'fecha_emision', 'payment_due_date')
            ->get();

        $rangos = [
            'corriente'      => 0.0,  // sin vencer
            'rango_1_30'     => 0.0,
            'rango_31_60'    => 0.0,
            'rango_61_90'    => 0.0,
            'rango_mas_90'   => 0.0,
        ];
        $total = 0.0;
        $cantidad = 0;

        foreach ($facturas as $f) {
            $saldo = (float) $f->valor_total;
            if ($saldo <= 0.01) continue;

            $venceStr = (string) ($f->payment_due_date ?? $f->fecha_emision);
            $diasVencido = $venceStr !== ''
                ? Carbon::parse($venceStr)->diffInDays($hoy, false)
                : 0;

            if ($diasVencido <= 0) {
                $rangos['corriente'] += $saldo;
            } elseif ($diasVencido <= 30) {
                $rangos['rango_1_30'] += $saldo;
            } elseif ($diasVencido <= 60) {
                $rangos['rango_31_60'] += $saldo;
            } elseif ($diasVencido <= 90) {
                $rangos['rango_61_90'] += $saldo;
            } else {
                $rangos['rango_mas_90'] += $saldo;
            }

            $total += $saldo;
            $cantidad++;
        }

        $rangos = array_map(fn ($v) => round($v, 2), $rangos);

        return array_merge($rangos, [
            'total'    => round($total, 2),
            'cantidad' => $cantidad,
            'porcentajes' => [
                'corriente'    => $total > 0 ? round($rangos['corriente']    / $total * 100, 1) : 0.0,
                'rango_1_30'   => $total > 0 ? round($rangos['rango_1_30']   / $total * 100, 1) : 0.0,
                'rango_31_60'  => $total > 0 ? round($rangos['rango_31_60']  / $total * 100, 1) : 0.0,
                'rango_61_90'  => $total > 0 ? round($rangos['rango_61_90']  / $total * 100, 1) : 0.0,
                'rango_mas_90' => $total > 0 ? round($rangos['rango_mas_90'] / $total * 100, 1) : 0.0,
            ],
        ]);
    }

    /**
     * Alertas operacionales para la gerencia.
     */
    private function alertas(Carbon $hoy): array
    {
        $alertas = [];

        // Periodos abiertos del año pasado (no cerrados a tiempo)
        $periodosVencidos = DB::table('periodos_contables')
            ->where('estado', 'abierto')
            ->where('fecha_fin', '<', $hoy->copy()->subDays(30)->toDateString())
            ->count();
        if ($periodosVencidos > 0) {
            $alertas[] = [
                'nivel'    => 'warning',
                'tipo'     => 'periodos_sin_cerrar',
                'mensaje'  => "{$periodosVencidos} periodos contables abiertos con más de 30 días de vencidos",
                'cantidad' => $periodosVencidos,
            ];
        }

        // Facturas vencidas (>30 días desde su vencimiento, no anuladas)
        $vencidas = DB::table('facturas')
            ->whereIn('estado', ['validado', 'enviado'])
            ->whereNotNull('payment_due_date')
            ->where('payment_due_date', '<', $hoy->copy()->subDays(30)->toDateString())
            ->count();
        if ($vencidas > 0) {
            $alertas[] = [
                'nivel'    => 'danger',
                'tipo'     => 'facturas_vencidas_30',
                'mensaje'  => "{$vencidas} facturas con mora superior a 30 días",
                'cantidad' => $vencidas,
            ];
        }

        // Asientos en estado borrador
        $borradores = DB::table('asientos')->where('estado', 'borrador')->count();
        if ($borradores > 0) {
            $alertas[] = [
                'nivel'    => 'info',
                'tipo'     => 'asientos_borrador',
                'mensaje'  => "{$borradores} asientos en borrador esperando aprobación",
                'cantidad' => $borradores,
            ];
        }

        // Extractos con líneas pendientes de conciliar
        try {
            $extractos = DB::table('extractos_bancarios as eb')
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('lineas_extracto as le')
                      ->whereColumn('le.extracto_id', 'eb.id')
                      ->where('le.estado_conciliacion', 'pendiente');
                })->count();
            if ($extractos > 0) {
                $alertas[] = [
                    'nivel'    => 'info',
                    'tipo'     => 'conciliacion_pendiente',
                    'mensaje'  => "{$extractos} extractos bancarios con líneas pendientes de conciliar",
                    'cantidad' => $extractos,
                ];
            }
        } catch (\Throwable) {
            // tablas opcionales: si no existen, no falla el dashboard
        }

        return $alertas;
    }

    private function pct(float $anterior, float $actual): float
    {
        if ($anterior == 0.0) return $actual > 0 ? 100.0 : 0.0;
        return round((($actual - $anterior) / abs($anterior)) * 100, 1);
    }
}
