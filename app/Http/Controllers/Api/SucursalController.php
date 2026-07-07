<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Sucursal;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Sucursal::orderBy('nombre')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string',
            'ciudad' => 'nullable|string',
            'es_principal' => 'boolean'
        ]);

        // Si es principal, desmarcamos las otras
        if ($validated['es_principal'] ?? false) {
            Sucursal::where('es_principal', true)->update(['es_principal' => false]);
        }

        $sucursal = Sucursal::create($validated);

        return response()->json([
            'success' => true,
            'data' => $sucursal
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $sucursal = Sucursal::findOrFail($id);
        
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string',
            'ciudad' => 'nullable|string',
            'es_principal' => 'boolean',
            'activa' => 'boolean'
        ]);

        if ($validated['es_principal'] ?? false) {
            Sucursal::where('id', '!=', $id)->update(['es_principal' => false]);
        }

        $sucursal->update($validated);

        return response()->json([
            'success' => true,
            'data' => $sucursal
        ]);
    }

    public function destroy($id)
    {
        $sucursal = Sucursal::findOrFail($id);
        $sucursal->update(['activa' => !$sucursal->activa]);
        return response()->json(['success' => true]);
    }
}
