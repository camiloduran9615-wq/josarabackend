<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Bodega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BodegasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bodega::with(['sucursal:id,nombre'])
            ->orderBy('nombre');

        if ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->boolean('activas', true)) {
            $query->where('activa', true);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $bodega = Bodega::with(['sucursal:id,nombre', 'inventarioCuenta:id,codigo,nombre'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $bodega]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'          => 'required|uuid|exists:sucursales,id',
            'codigo'               => 'required|string|max:20|unique:bodegas,codigo',
            'nombre'               => 'required|string|max:100',
            'tipo'                 => 'required|in:mercancia,materia_prima,producto_proceso,producto_terminado,consignacion,devoluciones,transito',
            'inventario_cuenta_id' => 'nullable|uuid|exists:cuentas_contables,id',
            'responsable_user_id'  => 'nullable|uuid|exists:users,id',
            'es_principal'         => 'boolean',
        ]);

        // Si se marca como principal, desmarcar las demás de la misma sucursal
        if ($validated['es_principal'] ?? false) {
            Bodega::where('sucursal_id', $validated['sucursal_id'])
                ->where('es_principal', true)
                ->update(['es_principal' => false]);
        }

        $bodega = Bodega::create($validated);
        $bodega->load('sucursal:id,nombre');

        return response()->json(['success' => true, 'data' => $bodega], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $bodega = Bodega::findOrFail($id);

        $validated = $request->validate([
            'sucursal_id'          => 'sometimes|uuid|exists:sucursales,id',
            'codigo'               => "sometimes|string|max:20|unique:bodegas,codigo,{$id}",
            'nombre'               => 'sometimes|string|max:100',
            'tipo'                 => 'sometimes|in:mercancia,materia_prima,producto_proceso,producto_terminado,consignacion,devoluciones,transito',
            'inventario_cuenta_id' => 'nullable|uuid|exists:cuentas_contables,id',
            'responsable_user_id'  => 'nullable|uuid|exists:users,id',
            'es_principal'         => 'boolean',
            'activa'               => 'boolean',
        ]);

        // Si se marca como principal, desmarcar las demás de la sucursal
        if ($validated['es_principal'] ?? false) {
            $sucursalId = $validated['sucursal_id'] ?? $bodega->sucursal_id;
            Bodega::where('sucursal_id', $sucursalId)
                ->where('id', '!=', $id)
                ->update(['es_principal' => false]);
        }

        $bodega->update($validated);
        $bodega->load('sucursal:id,nombre');

        return response()->json(['success' => true, 'data' => $bodega]);
    }

    public function destroy(string $id): JsonResponse
    {
        $bodega = Bodega::findOrFail($id);

        // Verificar si tiene movimientos de inventario
        $tieneMovimientos = $bodega->movimientos()->exists();

        if ($tieneMovimientos) {
            // Solo desactivar — no eliminar físicamente si tiene historial
            $bodega->update(['activa' => false]);
            return response()->json([
                'success' => true,
                'message' => 'Bodega desactivada (tiene movimientos de inventario).',
                'data'    => $bodega,
            ]);
        }

        $bodega->delete();

        return response()->json(['success' => true, 'data' => null]);
    }
}
