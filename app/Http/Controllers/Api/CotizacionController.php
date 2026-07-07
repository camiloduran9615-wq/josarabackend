<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Cotizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CotizacionController extends Controller
{
    public function index(): JsonResponse
    {
        $cotizaciones = Cotizacion::with('tercero')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $cotizaciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'centro_costo_id'                => 'nullable|uuid|exists:centros_costo,id',
            'tercero_id'                     => 'required|exists:terceros,id',
            'fecha'                          => 'required|date',
            'fecha_validez'                  => 'required|date|after:fecha',
            'condiciones_comerciales'        => 'nullable|string',
            'observaciones'                  => 'nullable|string',
            'items'                          => 'required|array|min:1',
            'items.*.nombre'                 => 'required|string',
            'items.*.cantidad'               => 'required|numeric|min:0.01',
            'items.*.precio_unitario'        => 'required|numeric|min:0',
            'items.*.porcentaje_descuento'   => 'nullable|numeric|min:0|max:100',
            'items.*.porcentaje_iva'         => 'nullable|numeric|min:0|max:100',
            'items.*.producto_id'            => 'nullable|exists:productos,id',
        ]);

        return DB::transaction(function () use ($request): JsonResponse {
            $numero = 'COT-' . str_pad((string)(Cotizacion::count() + 1), 6, '0', STR_PAD_LEFT);

            $bruto     = 0;
            $descuento = 0;
            $iva       = 0;
            $items     = [];

            foreach ($request->items as $item) {
                $pctDesc  = (float)($item['porcentaje_descuento'] ?? 0);
                $pctIva   = (float)($item['porcentaje_iva'] ?? 0);
                $subtotal = (float)$item['cantidad'] * (float)$item['precio_unitario'];
                $desc     = $subtotal * ($pctDesc / 100);
                $baseIva  = $subtotal - $desc;
                $ivaItem  = $baseIva * ($pctIva / 100);

                $bruto     += $subtotal;
                $descuento += $desc;
                $iva       += $ivaItem;

                $items[] = [
                    'producto_id'          => $item['producto_id'] ?? null,
                    'codigo_referencia'    => $item['codigo_referencia'] ?? null,
                    'nombre'               => $item['nombre'],
                    'descripcion'          => $item['descripcion'] ?? null,
                    'cantidad'             => $item['cantidad'],
                    'unidad_medida'        => $item['unidad_medida'] ?? 'Unidad',
                    'precio_unitario'      => $item['precio_unitario'],
                    'porcentaje_descuento' => $pctDesc,
                    'porcentaje_iva'       => $pctIva,
                    'valor_iva'            => round($ivaItem, 2),
                    'total'                => round($baseIva + $ivaItem, 2),
                ];
            }

            $cotizacion = Cotizacion::create([
                'centro_costo_id'         => $request->centro_costo_id,
                'tercero_id'              => $request->tercero_id,
                'numero'                  => $numero,
                'fecha'                   => $request->fecha,
                'fecha_validez'           => $request->fecha_validez,
                'condiciones_comerciales' => $request->condiciones_comerciales,
                'observaciones'           => $request->observaciones,
                'valor_bruto'             => round($bruto, 2),
                'valor_descuento'         => round($descuento, 2),
                'valor_iva'               => round($iva, 2),
                'valor_total'             => round($bruto - $descuento + $iva, 2),
                'estado'                  => 'borrador',
            ]);

            foreach ($items as $item) {
                $cotizacion->items()->create($item);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cotización creada.',
                'data'    => $cotizacion->load('tercero', 'items'),
            ], 201);
        });
    }

    public function show(string $id): JsonResponse
    {
        $cotizacion = Cotizacion::with(['tercero', 'items.producto'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $cotizacion]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $cotizacion = Cotizacion::findOrFail($id);

        $request->validate([
            'estado' => 'sometimes|in:borrador,enviada,aceptada,rechazada,vencida,facturada',
        ]);

        $cotizacion->update($request->only(['estado', 'observaciones', 'condiciones_comerciales']));

        return response()->json(['success' => true, 'data' => $cotizacion]);
    }

    public function destroy(string $id): JsonResponse
    {
        $cotizacion = Cotizacion::findOrFail($id);

        if (in_array($cotizacion->estado, ['aceptada', 'facturada'])) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar una cotización aceptada o facturada.'], 422);
        }

        $cotizacion->delete();

        return response()->json(['success' => true, 'message' => 'Cotización eliminada.']);
    }
}
