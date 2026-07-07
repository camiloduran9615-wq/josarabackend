<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TipoComprobante;
use Illuminate\Http\Request;

class TipoComprobanteController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => TipoComprobante::with('resolucion')->orderBy('codigo')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo'                => 'required|string|max:10|unique:tipo_comprobantes,codigo',
            'nombre'                => 'required|string|max:100',
            'tipo_documento'        => 'required|string|in:FV,DC,NC,ND',
            'resolucion_id'         => 'nullable|exists:resoluciones,id',
            'consecutivo_actual'    => 'nullable|integer|min:1',
            'prefijo_override'      => 'nullable|string|max:20',
            'observaciones_default'   => 'nullable|string',
            'habilitar_rete_iva'      => 'nullable|boolean',
            'habilitar_rete_ica'      => 'nullable|boolean',
            'habilitar_autorretencion'=> 'nullable|boolean',
            'titulo_pdf'              => 'nullable|string|max:100',
            'cuenta_ventas_id'        => 'nullable|uuid',
            'cuenta_clientes_id'      => 'nullable|uuid',
            'cuenta_iva_id'           => 'nullable|uuid',
            'vendedor_id'             => 'nullable|uuid',
        ], [
            'codigo.unique' => 'Ya existe un tipo de comprobante con este código.',
        ]);

        $comprobante = TipoComprobante::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de comprobante creado correctamente.',
            'data'    => $comprobante->load('resolucion'),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $comprobante = TipoComprobante::findOrFail($id);

        $validated = $request->validate([
            'codigo'                => 'sometimes|required|string|max:10|unique:tipo_comprobantes,codigo,' . $id,
            'nombre'                => 'sometimes|required|string|max:100',
            'tipo_documento'        => 'sometimes|required|string|in:FV,DC,NC,ND',
            'resolucion_id'         => 'nullable|exists:resoluciones,id',
            'consecutivo_actual'    => 'nullable|integer|min:1',
            'prefijo_override'      => 'nullable|string|max:20',
            'observaciones_default'   => 'nullable|string',
            'habilitar_rete_iva'      => 'nullable|boolean',
            'habilitar_rete_ica'      => 'nullable|boolean',
            'habilitar_autorretencion'=> 'nullable|boolean',
            'titulo_pdf'              => 'nullable|string|max:100',
            'cuenta_ventas_id'        => 'nullable|uuid',
            'cuenta_clientes_id'      => 'nullable|uuid',
            'cuenta_iva_id'           => 'nullable|uuid',
            'vendedor_id'             => 'nullable|uuid',
            'activo'                  => 'boolean',
        ]);

        $comprobante->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de comprobante actualizado.',
            'data'    => $comprobante->load('resolucion'),
        ]);
    }

    public function destroy(string $id)
    {
        $comprobante = TipoComprobante::findOrFail($id);
        $comprobante->update(['activo' => !$comprobante->activo]);

        return response()->json([
            'success' => true,
            'message' => $comprobante->activo ? 'Comprobante activado.' : 'Comprobante inactivado.',
        ]);
    }
}
