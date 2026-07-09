<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\UnidadMedidaDian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnidadMedidaDianController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = UnidadMedidaDian::query()->orderBy('nombre')->orderBy('codigo');

        if ($search = trim((string) $request->query('search', ''))) {
            $normalized = mb_strtolower($search);
            $query->where(function ($q) use ($normalized): void {
                $q->whereRaw('LOWER(codigo) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw("LOWER(COALESCE(descripcion, '')) LIKE ?", ["%{$normalized}%"]);
            });
        }

        if ($request->query('estado') === 'activos') {
            $query->where('activo', true);
        } elseif ($request->query('estado') === 'inactivos') {
            $query->where('activo', false);
        }

        $limit = min(max((int) $request->query('limit', 100), 1), 300);
        $unidades = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $unidades->map(fn (UnidadMedidaDian $unidad) => $this->serialize($unidad)),
            'total' => $unidades->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/', 'unique:unidades_medida_dian,codigo'],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $unidad = UnidadMedidaDian::create([
            'codigo' => strtoupper($validated['codigo']),
            'nombre' => $validated['nombre'],
            'descripcion' => $validated['descripcion'] ?? null,
            'activo' => $validated['activo'] ?? true,
            'sistema' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Unidad de medida DIAN creada correctamente.',
            'data' => $this->serialize($unidad),
        ], 201);
    }

    public function update(Request $request, string $tenant, string $codigo): JsonResponse
    {
        $unidad = UnidadMedidaDian::findOrFail(strtoupper($codigo));

        $validated = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $unidad->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Unidad de medida DIAN actualizada correctamente.',
            'data' => $this->serialize($unidad->refresh()),
        ]);
    }

    public function destroy(string $tenant, string $codigo): JsonResponse
    {
        $unidad = UnidadMedidaDian::findOrFail(strtoupper($codigo));
        $unidad->update(['activo' => ! $unidad->activo]);

        return response()->json([
            'success' => true,
            'message' => $unidad->activo
                ? 'Unidad de medida DIAN activada correctamente.'
                : 'Unidad de medida DIAN inactivada correctamente.',
            'data' => $this->serialize($unidad->refresh()),
        ]);
    }

    private function serialize(UnidadMedidaDian $unidad): array
    {
        return [
            'codigo' => $unidad->codigo,
            'nombre' => $unidad->nombre,
            'descripcion' => $unidad->descripcion,
            'activo' => $unidad->activo,
            'sistema' => $unidad->sistema,
        ];
    }
}
