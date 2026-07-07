<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Tenant\InventarioMovimiento;
use App\Models\Tenant\ProductoStockBodega;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de consulta del KARDEX y reportes de valorización.
 *
 * El KARDEX es O(n) porque los saldos están pre-calculados en cada movimiento
 * (columnas saldo_unidades_despues, saldo_valor_despues, costo_promedio_despues).
 * No hay recalculo en consulta — solo SELECT.
 */
final class KardexService
{
    /**
     * Retorna las líneas del KARDEX para un producto en una bodega específica.
     *
     * @return Collection<int, object{
     *     fecha: string,
     *     tipo: string,
     *     concepto: string,
     *     entrada_unidades: float|null,
     *     entrada_valor: float|null,
     *     salida_unidades: float|null,
     *     salida_valor: float|null,
     *     saldo_unidades: float,
     *     saldo_valor: float,
     *     costo_promedio: float,
     *     tercero: string|null,
     * }>
     */
    public function getKardex(
        string  $productoId,
        string  $bodegaId,
        Carbon  $desde,
        Carbon  $hasta,
    ): Collection {
        $movimientos = InventarioMovimiento::with(['tercero'])
            ->where('producto_id', $productoId)
            ->where('bodega_id', $bodegaId)
            ->whereBetween('created_at', [$desde->startOfDay(), $hasta->endOfDay()])
            ->orderBy('created_at')
            ->get();

        return $movimientos->map(function (InventarioMovimiento $m) {
            $esEntrada = in_array($m->tipo, [
                'entrada_compra', 'traslado_entrada', 'devolucion_venta',
                'ajuste_positivo', 'produccion_terminado',
            ]);

            return (object) [
                'id'               => $m->id,
                'fecha'            => $m->created_at->toDateTimeString(),
                'tipo'             => $m->tipo,
                'concepto'         => $m->concepto,
                'entrada_unidades' => $esEntrada ? (float) $m->cantidad : null,
                'entrada_valor'    => $esEntrada ? round((float) $m->cantidad * (float) $m->costo_unitario, 2) : null,
                'salida_unidades'  => ! $esEntrada ? (float) $m->cantidad : null,
                'salida_valor'     => ! $esEntrada ? round((float) $m->cantidad * (float) $m->costo_unitario, 2) : null,
                'saldo_unidades'   => (float) $m->saldo_unidades_despues,
                'saldo_valor'      => (float) $m->saldo_valor_despues,
                'costo_promedio'   => (float) $m->costo_promedio_despues,
                'tercero'          => $m->tercero?->razon_social ?? $m->tercero?->nombres,
                'documento_ref'    => $m->documento_ingreso_id ?? $m->factura_id,
            ];
        });
    }

    /**
     * Reporte de valorización de inventario por bodega/sucursal.
     * Útil para conciliar con el saldo de las cuentas 143x/145x/146x en el Balance.
     *
     * @return Collection<int, object{
     *     sucursal: string,
     *     bodega: string,
     *     bodega_tipo: string,
     *     producto_codigo: string,
     *     producto_nombre: string,
     *     categoria: string,
     *     saldo_unidades: float,
     *     costo_promedio: float,
     *     saldo_valor: float,
     *     cuenta_puc: string|null,
     * }>
     */
    public function getValorizacion(
        ?string $sucursalId  = null,
        ?string $categoriaId = null,
        ?string $bodegaId    = null,
    ): Collection {
        $query = DB::table('producto_stock_bodega as psb')
            ->join('productos as p',         'p.id',         '=', 'psb.producto_id')
            ->join('bodegas as b',            'b.id',         '=', 'psb.bodega_id')
            ->join('sucursales as s',         's.id',         '=', 'b.sucursal_id')
            ->leftJoin('categorias as cat',   'cat.id',       '=', 'p.categoria_id')
            ->leftJoin('cuentas_contables as cc', 'cc.id',   '=', 'cat.inventario_cuenta_id')
            ->select([
                's.nombre as sucursal',
                'b.nombre as bodega',
                'b.tipo as bodega_tipo',
                'p.codigo as producto_codigo',
                'p.nombre as producto_nombre',
                DB::raw("COALESCE(cat.nombre, 'Sin categoría') as categoria"),
                'psb.saldo_unidades',
                'psb.costo_promedio',
                'psb.saldo_valor',
                DB::raw("COALESCE(cc.codigo, '') as cuenta_puc"),
            ])
            ->where('psb.saldo_unidades', '>', 0)
            ->whereNull('p.deleted_at');

        if ($sucursalId) {
            $query->where('b.sucursal_id', $sucursalId);
        }
        if ($categoriaId) {
            $query->where('p.categoria_id', $categoriaId);
        }
        if ($bodegaId) {
            $query->where('psb.bodega_id', $bodegaId);
        }

        return $query->orderBy('s.nombre')
                     ->orderBy('b.nombre')
                     ->orderBy('p.nombre')
                     ->get();
    }

    /**
     * Resumen de stock por producto (suma de todas las bodegas).
     */
    public function getStockTotal(string $productoId): Collection
    {
        return DB::table('producto_stock_bodega as psb')
            ->join('bodegas as b', 'b.id', '=', 'psb.bodega_id')
            ->join('sucursales as s', 's.id', '=', 'b.sucursal_id')
            ->where('psb.producto_id', $productoId)
            ->select([
                's.nombre as sucursal',
                'b.nombre as bodega',
                'b.tipo as bodega_tipo',
                'psb.saldo_unidades',
                'psb.costo_promedio',
                'psb.saldo_valor',
                'psb.ultima_entrada_at',
                'psb.ultima_salida_at',
            ])
            ->orderBy('s.nombre')
            ->orderBy('b.nombre')
            ->get();
    }
}
