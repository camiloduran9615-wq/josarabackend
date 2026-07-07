<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventario\KardexService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KardexController extends Controller
{
    public function __construct(
        protected KardexService $kardexService
    ) {}

    /**
     * GET /{tenant}/kardex
     * Devuelve el Kardex completo de un producto en una bodega para un rango de fechas.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'producto_id' => 'required|uuid|exists:productos,id',
            'bodega_id'   => 'required|uuid|exists:bodegas,id',
            'desde'       => 'required|date',
            'hasta'       => 'required|date|after_or_equal:desde',
        ]);

        $lineas = $this->kardexService->getKardex(
            productoId: $request->producto_id,
            bodegaId:   $request->bodega_id,
            desde:      Carbon::parse($request->desde),
            hasta:      Carbon::parse($request->hasta),
        );

        return response()->json([
            'success' => true,
            'data'    => $lineas,
            'meta'    => [
                'total_movimientos' => $lineas->count(),
                'producto_id'       => $request->producto_id,
                'bodega_id'         => $request->bodega_id,
                'desde'             => $request->desde,
                'hasta'             => $request->hasta,
            ],
        ]);
    }

    /**
     * GET /{tenant}/kardex/valorizacion
     * Devuelve el inventario valorizado por bodega (stock × CPP).
     */
    public function valorizacion(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'  => 'nullable|uuid|exists:sucursales,id',
            'bodega_id'    => 'nullable|uuid|exists:bodegas,id',
            'categoria_id' => 'nullable|uuid|exists:categorias,id',
        ]);

        $valorizacion = $this->kardexService->getValorizacion(
            sucursalId:  $request->sucursal_id,
            categoriaId: $request->categoria_id,
            bodegaId:    $request->bodega_id,
        );

        $totalValor = $valorizacion->sum('saldo_valor');

        return response()->json([
            'success' => true,
            'data'    => $valorizacion,
            'meta'    => [
                'total_registros' => $valorizacion->count(),
                'total_valor'     => round($totalValor, 2),
            ],
        ]);
    }

    /**
     * GET /{tenant}/kardex/stock/{productoId}
     * Devuelve el stock del producto en todas sus bodegas.
     */
    public function stockTotal(string $productoId): JsonResponse
    {
        // Validar que existe
        abort_unless(
            \App\Models\Tenant\Producto::where('id', $productoId)->exists(),
            404,
            'Producto no encontrado.'
        );

        $stock = $this->kardexService->getStockTotal($productoId);

        return response()->json([
            'success' => true,
            'data'    => $stock,
            'meta'    => [
                'producto_id'     => $productoId,
                'total_unidades'  => $stock->sum('saldo_unidades'),
                'total_valor'     => round($stock->sum('saldo_valor'), 2),
                'total_bodegas'   => $stock->count(),
            ],
        ]);
    }
}
