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
        ];
    }
}
