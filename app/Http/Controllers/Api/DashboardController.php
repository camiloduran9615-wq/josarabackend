<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\CuentaSaldo;
use App\Models\Tenant\Factura;
use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\ReciboCaja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /{tenant}/dashboard
 *
 * KPIs financieros del período actual para el dashboard.
 * Los queries son ligeros — agregan sobre índices existentes.
 * Cache 5 min, invalidado por AsientoAprobado.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $hoy         = Carbon::today();
        $inicioMes   = $hoy->copy()->startOfMonth();
        $finMes      = $hoy->copy()->endOfMonth();
        $inicioAnterior = $hoy->copy()->subMonth()->startOfMonth();
        $finAnterior    = $hoy->copy()->subMonth()->endOfMonth();

        $inicio = microtime(true);

        // ── 1. Facturas del mes ────────────────────────────────────────────
        $facturasMes = Factura::whereBetween('fecha_emision', [$inicioMes, $finMes])
            ->whereIn('estado', ['validado', 'enviado'])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(valor_total), 0) as total')
            ->first();

        $facturasAnterior = Factura::whereBetween('fecha_emision', [$inicioAnterior, $finAnterior])
            ->whereIn('estado', ['validado', 'enviado'])
            ->selectRaw('COALESCE(SUM(valor_total), 0) as total')
            ->value('total') ?? 0;

        // ── 2. Cartera por cobrar (CxC) — cuenta 1305xx ───────────────────
        $cxcSaldo = CuentaSaldo::whereHas('cuenta', fn ($q) => $q->where('codigo', 'LIKE', '1305%'))
            ->selectRaw('COALESCE(SUM(saldo_final_debito - saldo_final_credito), 0) as saldo')
            ->value('saldo') ?? 0;

        // ── 3. Ingresos vs Gastos del mes (cuentas clase 4 y 5) ──────────
        $ingresosMes = $this->sumaMovimientoClase('4', $inicioMes, $finMes);
        $gastosMes   = $this->sumaMovimientoClase('5', $inicioMes, $finMes);
        $utilidadMes = $ingresosMes - $gastosMes;

        // ── 4. Tendencia diaria de ingresos — últimos 15 días ────────────
        $tendencia = $this->tendenciaDiaria(15);

        // ── 5. Compras del mes ────────────────────────────────────────────
        $comprasMes = DocumentoIngreso::whereBetween('fecha', [$inicioMes, $finMes])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(valor_total), 0) as total')
            ->first();

        // ── 6. Recibos de caja del mes ────────────────────────────────────
        $cobradoMes = ReciboCaja::whereBetween('fecha', [$inicioMes, $finMes])
            ->where('estado', 'activo')
            ->selectRaw('COALESCE(SUM(valor_recibido), 0) as total')
            ->value('total') ?? 0;

        // ── 7. Últimos 5 asientos aprobados ──────────────────────────────
        $asientosRecientes = Asiento::where('estado', 'aprobado')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'fecha', 'glosa', 'descripcion', 'tipo_comprobante']);

        $ms = round((microtime(true) - $inicio) * 1000, 1);

        return response()->json([
            'success' => true,
            'data'    => [
                'periodo'    => [
                    'inicio' => $inicioMes->toDateString(),
                    'fin'    => $finMes->toDateString(),
                    'label'  => $hoy->translatedFormat('F Y'),
                ],
                'kpis' => [
                    'facturas_mes' => [
                        'cantidad'   => (int) $facturasMes->cantidad,
                        'total'      => (float) $facturasMes->total,
                        'variacion'  => $this->variacion((float) $facturasAnterior, (float) $facturasMes->total),
                    ],
                    'cartera_cxc' => [
                        'saldo' => (float) $cxcSaldo,
                    ],
                    'ingresos_mes' => [
                        'total'     => $ingresosMes,
                        'utilidad'  => $utilidadMes,
                        'gastos'    => $gastosMes,
                    ],
                    'cobrado_mes' => [
                        'total' => (float) $cobradoMes,
                    ],
                    'compras_mes' => [
                        'cantidad' => (int) $comprasMes->cantidad,
                        'total'    => (float) $comprasMes->total,
                    ],
                ],
                'tendencia_ingresos' => $tendencia,
                'asientos_recientes' => $asientosRecientes,
                'meta' => ['ms' => $ms],
            ],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sumaMovimientoClase(string $clase, Carbon $desde, Carbon $hasta): float
    {
        return (float) DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereBetween('a.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('cc.codigo', 'LIKE', $clase . '%')
            ->selectRaw($clase === '4'
                ? 'COALESCE(SUM(al.credito - al.debito), 0) as suma'
                : 'COALESCE(SUM(al.debito - al.credito), 0) as suma'
            )
            ->value('suma') ?? 0.0;
    }

    private function tendenciaDiaria(int $dias): array
    {
        $desde = Carbon::today()->subDays($dias - 1)->toDateString();
        $hasta = Carbon::today()->toDateString();

        $rows = DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->where('a.estado', 'aprobado')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->where('cc.codigo', 'LIKE', '4%')
            ->selectRaw("a.fecha::date as dia, COALESCE(SUM(al.credito - al.debito), 0) as ingreso")
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->keyBy('dia');

        $resultado = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = Carbon::today()->subDays($i)->toDateString();
            $resultado[] = [
                'dia'     => $fecha,
                'ingreso' => (float) ($rows[$fecha]->ingreso ?? 0),
            ];
        }

        return $resultado;
    }

    private function variacion(float $anterior, float $actual): float
    {
        if ($anterior == 0.0) {
            return $actual > 0 ? 100.0 : 0.0;
        }

        return round((($actual - $anterior) / abs($anterior)) * 100, 1);
    }
}
