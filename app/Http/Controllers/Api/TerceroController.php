<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Tercero;
use App\Services\FactusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TerceroController extends Controller
{

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Tercero::orderBy('razon_social')->get()
        ]);
    }

    /**
     * Consulta datos de un adquiriente en la DIAN a través de Factus.
     */
    public function searchDian(Request $request, FactusService $factusService)
    {
        $request->validate([
            'tipo_documento_id' => 'required|string',
            'identificacion' => 'required|string',
        ]);

        $data = $factusService->getAcquirerData(
            $request->tipo_documento_id,
            $request->identificacion
        );

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron datos en la DIAN para este documento.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data['data']
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'identificacion_documento_id' => ['required', 'string', 'max:5'],
            'identificacion'              => ['required', 'string', 'max:20', 'unique:terceros,identificacion'],
            'dv'                          => ['nullable', 'string', 'max:1'],
            'sucursal'                    => ['nullable', 'string', 'max:10'],
            'organizacion_juridica_id'    => ['nullable', 'string', 'max:2'],
            'tributo_id'                  => ['nullable', 'string', 'max:5'],
            'tipo_persona'                => ['nullable', 'string', 'max:30'],
            'nombres'                     => ['nullable', 'string', 'max:255'],
            'apellidos'                   => ['nullable', 'string', 'max:255'],
            'razon_social'                => ['required', 'string', 'max:255'],
            'nombre_comercial'            => ['nullable', 'string', 'max:255'],
            'direccion'                   => ['nullable', 'string', 'max:255'],
            'email'                       => ['nullable', 'email', 'max:255'],
            'telefono'                    => ['nullable', 'string', 'max:20'],
            'municipio_id'                => ['nullable', 'string', 'max:10'],
            'codigo_postal'               => ['nullable', 'string', 'max:10'],
            'regimen_iva'                 => ['nullable', 'string', 'max:50'],
            'responsabilidades_fiscales'  => ['nullable', 'array'],
            'codigo_ciiu'                 => ['nullable', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
            'observaciones'               => ['nullable', 'string'],
            'es_cliente'                  => ['boolean'],
            'es_proveedor'                => ['boolean'],
            'es_empleado'                 => ['boolean'],
        ], [
            'identificacion.unique'   => 'Este número de identificación ya existe. Búscalo y usa la opción editar.',
            'identificacion.required' => 'El número de identificación es obligatorio.',
            'razon_social.required'   => 'La razón social o nombre es obligatorio.',
            'email.email'             => 'El email no tiene un formato válido.',
        ]);

        $tercero = Tercero::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tercero creado correctamente.',
            'data' => $tercero
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $tercero = Tercero::findOrFail($id);

        $validated = $request->validate([
            'identificacion_documento_id' => ['sometimes', 'required', 'string', 'max:5'],
            'identificacion'              => ['sometimes', 'required', 'string', 'max:20', Rule::unique('terceros')->ignore($tercero->id)],
            'dv'                          => ['nullable', 'string', 'max:1'],
            'sucursal'                    => ['nullable', 'string', 'max:10'],
            'organizacion_juridica_id'    => ['nullable', 'string', 'max:2'],
            'tributo_id'                  => ['nullable', 'string', 'max:5'],
            'tipo_persona'                => ['nullable', 'string', 'max:30'],
            'nombres'                     => ['nullable', 'string', 'max:255'],
            'apellidos'                   => ['nullable', 'string', 'max:255'],
            'razon_social'                => ['sometimes', 'required', 'string', 'max:255'],
            'nombre_comercial'            => ['nullable', 'string', 'max:255'],
            'direccion'                   => ['nullable', 'string', 'max:255'],
            'email'                       => ['nullable', 'email', 'max:255'],
            'telefono'                    => ['nullable', 'string', 'max:20'],
            'municipio_id'                => ['nullable', 'string', 'max:10'],
            'codigo_postal'               => ['nullable', 'string', 'max:10'],
            'regimen_iva'                 => ['nullable', 'string', 'max:50'],
            'responsabilidades_fiscales'  => ['nullable', 'array'],
            'codigo_ciiu'                 => ['nullable', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
            'observaciones'               => ['nullable', 'string'],
            'es_cliente'                  => ['boolean'],
            'es_proveedor'                => ['boolean'],
            'es_empleado'                 => ['boolean'],
            'activo'                      => ['boolean'],
        ]);

        $tercero->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tercero actualizado correctamente.',
            'data' => $tercero
        ]);
    }

    public function destroy(string $id)
    {
        $tercero = Tercero::findOrFail($id);
        $tercero->delete(); 

        return response()->json([
            'success' => true,
            'message' => 'Tercero inactivado correctamente.'
        ]);
    }
}
