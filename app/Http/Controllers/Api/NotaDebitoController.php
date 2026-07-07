<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\NotaDebito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotaDebitoController extends Controller
{
    public function index(): JsonResponse
    {
        $notas = NotaDebito::with(['tercero', 'factura'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $notas]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'centro_costo_id' => 'nullable|uuid|exists:centros_costo,id',
            'tercero_id'      => 'required|exists:terceros,id',
            'factura_id'      => 'nullable|exists:facturas,id',
            'fecha'           => 'required|date',
            'concepto_codigo' => 'required|string',
            'descripcion'     => 'required|string|max:500',
            'items'           => 'required|array|min:1',
            'items.*.nombre'  => 'required|string',
            'items.*.cantidad'=> 'required|numeric|min:0.01',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.porcentaje_iva'  => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($request): JsonResponse {
            $numero = 'ND-' . str_pad((string)(NotaDebito::count() + 1), 6, '0', STR_PAD_LEFT);

            $bruto = 0;
            $iva   = 0;
            $items = [];

            foreach ($request->items as $item) {
                $pct      = (float)($item['porcentaje_iva'] ?? 19);
                $subtotal = (float)$item['cantidad'] * (float)$item['precio_unitario'];
                $ivaItem  = $subtotal * ($pct / 100);
                $bruto   += $subtotal;
                $iva     += $ivaItem;
                $items[]  = [
                    'nombre'          => $item['nombre'],
                    'cantidad'        => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'porcentaje_iva'  => $pct,
                    'valor_iva'       => round($ivaItem, 2),
                    'total'           => round($subtotal + $ivaItem, 2),
                ];
            }

            $nota = NotaDebito::create([
                'centro_costo_id' => $request->centro_costo_id,
                'tercero_id'      => $request->tercero_id,
                'factura_id'      => $request->factura_id,
                'numero'          => $numero,
                'fecha'           => $request->fecha,
                'concepto_codigo' => $request->concepto_codigo,
                'descripcion'     => $request->descripcion,
                'valor_bruto'     => round($bruto, 2),
                'valor_iva'       => round($iva, 2),
                'valor_total'     => round($bruto + $iva, 2),
                'estado'          => 'borrador',
            ]);

            foreach ($items as $item) {
                $nota->items()->create($item);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nota débito creada.',
                'data'    => $nota->load('tercero', 'items'),
            ], 201);
        });
    }

    public function show(string $id): JsonResponse
    {
        $nota = NotaDebito::with(['tercero', 'factura', 'items'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $nota]);
    }

    public function destroy(string $id): JsonResponse
    {
        $nota = NotaDebito::findOrFail($id);

        if ($nota->estado === 'anulado') {
            return response()->json(['success' => false, 'message' => 'Ya está anulada.'], 422);
        }

        $nota->update(['estado' => 'anulado']);
        $nota->delete();

        return response()->json(['success' => true, 'message' => 'Nota débito anulada.']);
    }
}
