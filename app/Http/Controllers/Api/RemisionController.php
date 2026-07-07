<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Remision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RemisionController extends Controller
{
    public function index(): JsonResponse
    {
        $remisiones = Remision::with(['tercero', 'factura'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $remisiones]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'centro_costo_id'    => 'nullable|uuid|exists:centros_costo,id',
            'tercero_id'         => 'required|exists:terceros,id',
            'fecha'              => 'required|date',
            'fecha_entrega'      => 'nullable|date|after_or_equal:fecha',
            'direccion_entrega'  => 'nullable|string',
            'transportista'      => 'nullable|string',
            'numero_guia'        => 'nullable|string',
            'observaciones'      => 'nullable|string',
            'items'              => 'required|array|min:1',
            'items.*.nombre'     => 'required|string',
            'items.*.cantidad'   => 'required|numeric|min:0.01',
            'items.*.precio_unitario' => 'nullable|numeric|min:0',
            'items.*.unidad_medida'   => 'nullable|string',
            'items.*.producto_id'     => 'nullable|exists:productos,id',
        ]);

        return DB::transaction(function () use ($request): JsonResponse {
            $numero = 'REM-' . str_pad((string)(Remision::count() + 1), 6, '0', STR_PAD_LEFT);

            $total = 0;
            $items = [];

            foreach ($request->items as $item) {
                $precio   = (float)($item['precio_unitario'] ?? 0);
                $subtotal = (float)$item['cantidad'] * $precio;
                $total   += $subtotal;
                $items[]  = [
                    'producto_id'       => $item['producto_id'] ?? null,
                    'codigo_referencia' => $item['codigo_referencia'] ?? null,
                    'nombre'            => $item['nombre'],
                    'cantidad'          => $item['cantidad'],
                    'unidad_medida'     => $item['unidad_medida'] ?? 'Unidad',
                    'precio_unitario'   => $precio,
                    'total'             => round($subtotal, 2),
                ];
            }

            $remision = Remision::create([
                'centro_costo_id'   => $request->centro_costo_id,
                'tercero_id'        => $request->tercero_id,
                'numero'            => $numero,
                'fecha'             => $request->fecha,
                'fecha_entrega'     => $request->fecha_entrega,
                'direccion_entrega' => $request->direccion_entrega,
                'transportista'     => $request->transportista,
                'numero_guia'       => $request->numero_guia,
                'valor_total'       => round($total, 2),
                'estado'            => 'enviada',
                'observaciones'     => $request->observaciones,
            ]);

            foreach ($items as $item) {
                $remision->items()->create($item);
            }

            return response()->json([
                'success' => true,
                'message' => 'Remisión creada.',
                'data'    => $remision->load('tercero', 'items'),
            ], 201);
        });
    }

    public function show(string $id): JsonResponse
    {
        $remision = Remision::with(['tercero', 'factura', 'items.producto'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $remision]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $remision = Remision::findOrFail($id);

        $request->validate([
            'estado' => 'sometimes|in:borrador,enviada,facturada,anulada',
        ]);

        $remision->update($request->only(['estado', 'factura_id', 'numero_guia', 'observaciones']));

        return response()->json(['success' => true, 'data' => $remision]);
    }

    public function destroy(string $id): JsonResponse
    {
        $remision = Remision::findOrFail($id);

        if ($remision->estado === 'anulada') {
            return response()->json(['success' => false, 'message' => 'Ya está anulada.'], 422);
        }

        $remision->update(['estado' => 'anulada']);
        $remision->delete();

        return response()->json(['success' => true, 'message' => 'Remisión anulada.']);
    }
}
