<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TipoDocumentoIngreso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoDocumentoIngresoController extends Controller
{
    public function index(): JsonResponse
    {
        $tipos = TipoDocumentoIngreso::with([
            'cuentaInventario:id,codigo,nombre',
            'cuentaGasto:id,codigo,nombre',
            'cuentaProveedor:id,codigo,nombre',
            'cuentaIvaDescontable:id,codigo,nombre',
        ])
        ->where('activo', true)
        ->orderBy('codigo')
        ->get();

        return response()->json(['success' => true, 'data' => $tipos]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo'                    => 'required|string|max:20|unique:tipos_documento_ingreso,codigo',
            'nombre'                    => 'required|string|max:100',
            'descripcion'               => 'nullable|string|max:500',
            'prefijo_numero'            => 'nullable|string|max:10',
            'afecta_inventario'         => 'boolean',
            'tipo_linea_default'        => 'required|in:producto,gasto,activo_fijo',
            'cuenta_inventario_id'      => 'nullable|uuid|exists:cuentas_contables,id',
            'cuenta_gasto_id'           => 'nullable|uuid|exists:cuentas_contables,id',
            'cuenta_proveedor_id'       => 'nullable|uuid|exists:cuentas_contables,id',
            'cuenta_iva_descontable_id' => 'nullable|uuid|exists:cuentas_contables,id',
            'retefuente_concepto'       => 'nullable|string|max:50',
            'retefuente_tasa'           => 'nullable|numeric|min:0|max:100',
            'reteica_concepto'          => 'nullable|string|max:50',
            'reteica_tasa'              => 'nullable|numeric|min:0|max:100',
        ]);

        $tipo = TipoDocumentoIngreso::create($validated);
        $tipo->load(['cuentaInventario:id,codigo,nombre', 'cuentaGasto:id,codigo,nombre', 'cuentaProveedor:id,codigo,nombre']);

        return response()->json(['success' => true, 'data' => $tipo], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tipo = TipoDocumentoIngreso::findOrFail($id);

        $validated = $request->validate([
            'codigo'                    => "sometimes|string|max:20|unique:tipos_documento_ingreso,codigo,{$id}",
            'nombre'                    => 'sometimes|string|max:100',
            'descripcion'               => 'nullable|string|max:500',
            'prefijo_numero'            => 'nullable|string|max:10',
            'afecta_inventario'         => 'boolean',
            'tipo_linea_default'        => 'sometimes|in:producto,gasto,activo_fijo',
            'cuenta_inventario_id'      => 'nullable|uuid|exists:cuentas_contables,id',
            'cuenta_gasto_id'           => 'nullable|uuid|exists:cuentas_contables,id',
            'cuenta_proveedor_id'       => 'nullable|uuid|exists:cuentas_contables,id',
            'cuenta_iva_descontable_id' => 'nullable|uuid|exists:cuentas_contables,id',
            'retefuente_concepto'       => 'nullable|string|max:50',
            'retefuente_tasa'           => 'nullable|numeric|min:0|max:100',
            'reteica_concepto'          => 'nullable|string|max:50',
            'reteica_tasa'              => 'nullable|numeric|min:0|max:100',
            'activo'                    => 'boolean',
        ]);

        $tipo->update($validated);
        $tipo->load(['cuentaInventario:id,codigo,nombre', 'cuentaGasto:id,codigo,nombre', 'cuentaProveedor:id,codigo,nombre']);

        return response()->json(['success' => true, 'data' => $tipo]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tipo = TipoDocumentoIngreso::findOrFail($id);
        $tipo->update(['activo' => false]);
        $tipo->delete();

        return response()->json(['success' => true, 'data' => null]);
    }
}
