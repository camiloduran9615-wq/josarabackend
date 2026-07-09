<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MunicipioDane;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint público (catálogo DANE compartido — no requiere tenant).
 *
 * Útil para autocompletar municipios en formularios de terceros, sucursales,
 * perfil empresa, etc.
 */
class MunicipioDaneController extends Controller
{
    /**
     * GET /api/v1/municipios?search=garz&limit=20
     * GET /api/v1/municipios?departamento=41
     * GET /api/v1/municipios (sin filtros — devuelve los primeros 20 alfabéticos)
     */
    public function index(Request $request): JsonResponse
    {
        $query = MunicipioDane::query()
            ->where('activo', true)
            ->orderBy('municipio_nombre');

        if ($search = $request->query('search')) {
            $term = '%' . strtolower((string) $search) . '%';
            $query->where(function ($q) use ($term, $search) {
                $q->whereRaw('LOWER(municipio_nombre) LIKE ?', [$term])
                  ->orWhere('codigo_dane', 'LIKE', "%{$search}%")
                  ->orWhereRaw('LOWER(departamento_nombre) LIKE ?', [$term]);
            });
        }

        if ($depto = $request->query('departamento')) {
            $query->where('departamento_dane', $depto);
        }

        $limit = min((int) $request->query('limit', 20), 100);
        $municipios = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data'    => $municipios->map(fn ($m) => $this->serialize($m)),
            'total'   => $municipios->count(),
        ]);
    }

    /**
     * GET /api/v1/{tenant}/municipios-dane
     * Administración protegida del catálogo central DANE.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = MunicipioDane::query()
            ->orderBy('departamento_nombre')
            ->orderBy('municipio_nombre');

        if ($search = $request->query('search')) {
            $term = '%' . strtolower((string) $search) . '%';
            $query->where(function ($q) use ($term, $search) {
                $q->whereRaw('LOWER(municipio_nombre) LIKE ?', [$term])
                  ->orWhere('codigo_dane', 'LIKE', "%{$search}%")
                  ->orWhereRaw('LOWER(departamento_nombre) LIKE ?', [$term]);
            });
        }

        if ($depto = $request->query('departamento')) {
            $query->where('departamento_dane', $depto);
        }

        if ($request->query('estado') === 'activos') {
            $query->where('activo', true);
        } elseif ($request->query('estado') === 'inactivos') {
            $query->where('activo', false);
        }

        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $municipios = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data'    => $municipios->map(fn ($m) => $this->serialize($m)),
            'total'   => $municipios->count(),
        ]);
    }

    /**
     * POST /api/v1/{tenant}/municipios-dane
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_dane'         => ['required', 'string', 'max:8', 'regex:/^[0-9]{5,8}$/'],
            'municipio_nombre'    => ['required', 'string', 'max:100'],
            'departamento_dane'   => ['required', 'string', 'size:2', 'regex:/^[0-9]{2}$/'],
            'departamento_nombre' => ['required', 'string', 'max:100'],
            'region'              => ['nullable', 'string', 'max:50'],
            'activo'              => ['sometimes', 'boolean'],
        ]);

        $codigo = str_pad($validated['codigo_dane'], 5, '0', STR_PAD_LEFT);
        if (MunicipioDane::query()->whereKey($codigo)->exists()) {
            return response()->json([
                'message' => 'El código DANE ya existe.',
                'errors'  => ['codigo_dane' => ['El código DANE ya existe.']],
            ], 422);
        }

        $municipio = MunicipioDane::query()->create([
            ...$validated,
            'codigo_dane'         => $codigo,
            'departamento_dane'   => str_pad($validated['departamento_dane'], 2, '0', STR_PAD_LEFT),
            'municipio_nombre'    => trim($validated['municipio_nombre']),
            'departamento_nombre' => trim($validated['departamento_nombre']),
            'region'              => isset($validated['region']) ? trim((string) $validated['region']) : null,
            'activo'              => $validated['activo'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Municipio DANE creado correctamente.',
            'data'    => $this->serialize($municipio),
        ], 201);
    }

    /**
     * PUT /api/v1/{tenant}/municipios-dane/{codigo}
     */
    public function update(Request $request, string $codigo): JsonResponse
    {
        $municipio = MunicipioDane::query()->findOrFail($codigo);

        $validated = $request->validate([
            'municipio_nombre'    => ['sometimes', 'required', 'string', 'max:100'],
            'departamento_dane'   => ['sometimes', 'required', 'string', 'size:2', 'regex:/^[0-9]{2}$/'],
            'departamento_nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'region'              => ['nullable', 'string', 'max:50'],
            'activo'              => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['municipio_nombre'])) {
            $validated['municipio_nombre'] = trim($validated['municipio_nombre']);
        }
        if (isset($validated['departamento_nombre'])) {
            $validated['departamento_nombre'] = trim($validated['departamento_nombre']);
        }
        if (array_key_exists('region', $validated)) {
            $validated['region'] = $validated['region'] !== null ? trim((string) $validated['region']) : null;
        }
        if (isset($validated['departamento_dane'])) {
            $validated['departamento_dane'] = str_pad($validated['departamento_dane'], 2, '0', STR_PAD_LEFT);
        }

        $municipio->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Municipio DANE actualizado correctamente.',
            'data'    => $this->serialize($municipio->refresh()),
        ]);
    }

    /**
     * DELETE /api/v1/{tenant}/municipios-dane/{codigo}
     * No elimina físicamente: alterna activo/inactivo para no romper referencias históricas.
     */
    public function destroy(string $codigo): JsonResponse
    {
        $municipio = MunicipioDane::query()->findOrFail($codigo);
        $municipio->update(['activo' => ! $municipio->activo]);

        return response()->json([
            'success' => true,
            'message' => $municipio->activo ? 'Municipio DANE activado.' : 'Municipio DANE inactivado.',
            'data'    => $this->serialize($municipio->refresh()),
        ]);
    }

    /**
     * GET /api/v1/municipios/{codigo}
     */
    public function show(string $codigo): JsonResponse
    {
        $municipio = MunicipioDane::query()->find($codigo);

        if (!$municipio) {
            return response()->json([
                'success' => false,
                'message' => "Municipio con código DANE '{$codigo}' no encontrado.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($municipio),
        ]);
    }

    /** Estructura unificada hacia el frontend (alias consistentes). */
    private function serialize(MunicipioDane $m): array
    {
        return [
            'codigo'              => $m->codigo_dane,
            'nombre'              => $m->municipio_nombre,
            'departamento_codigo' => $m->departamento_dane,
            'departamento_nombre' => $m->departamento_nombre,
            'nombre_completo'     => "{$m->municipio_nombre}, {$m->departamento_nombre}",
            'region'              => $m->region,
            'activo'              => (bool) $m->activo,
        ];
    }
}
