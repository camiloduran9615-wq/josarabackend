<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CentroCosto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CentrosCostoController extends Controller
{
    /**
     * GET /centros-costo
     *
     * Devuelve lista plana con parent y sucursal cargados, ordenada por
     * nivel → código, para que el frontend pueda construir el árbol.
     *
     * ?activos=false  → incluye los inactivos (panel de configuración)
     * ?sucursal_id=X  → filtra por sucursal (y los globales)
     * ?tree=true      → devuelve árbol anidado (opcional)
     */
    public function index(Request $request): JsonResponse
    {
        $query = CentroCosto::with([
            'sucursal:id,nombre',
            'parent:id,codigo,nombre',
        ])
        ->orderBy('nivel')
        ->orderBy('codigo');

        // Filtro activos: por defecto solo activos, ?activos=false devuelve todos
        if ($request->boolean('activos', true)) {
            $query->where('activo', true);
        }

        // Filtro por sucursal (incluye globales null)
        if ($request->filled('sucursal_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('sucursal_id', $request->sucursal_id)
                  ->orWhereNull('sucursal_id');
            });
        }

        $items = $query->get();

        // Modo árbol anidado (para el panel de configuración)
        if ($request->boolean('tree', false)) {
            $items = $this->buildTree($items->toArray());
        }

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }

    /**
     * POST /centros-costo
     *
     * Crea un centro raíz (parent_id null) o subcentro (nivel calculado).
     * Máximo 3 niveles de profundidad.
     */
    public function store(Request $request): JsonResponse
    {
        // Obtener parent_id antes de la validación para usarlo en la regla unique
        $parentId = $request->input('parent_id') ?: null;

        $validated = $request->validate([
            'codigo' => [
                'required', 'string', 'max:20',
                // Único dentro del mismo nivel jerárquico (mismo parent_id).
                // Permite Centro=1 + Subcentro=1 (son hermanos de distintos padres).
                Rule::unique('centros_costo')->where(
                    fn ($q) => $q->where('parent_id', $parentId)
                ),
            ],
            'nombre'      => 'required|string|max:100',
            'sucursal_id' => 'sometimes|nullable|string|exists:sucursales,id',
            'parent_id'   => 'sometimes|nullable|string|exists:centros_costo,id',
        ]);

        // Calcular nivel y validar máximo 3 niveles
        $nivel    = 1;

        if (!empty($parentId)) {
            $parent = CentroCosto::findOrFail($parentId);
            $nivel  = $parent->nivel + 1;

            if ($nivel > 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Máximo 3 niveles de jerarquía (Centro → Subcentro → Sub-subcentro).',
                ], 422);
            }
        }

        $cc = CentroCosto::create([
            ...$validated,
            'nivel'  => $nivel,
            'activo' => true,
        ]);

        $cc->load(['sucursal:id,nombre', 'parent:id,codigo,nombre']);

        return response()->json(['success' => true, 'data' => $cc], 201);
    }

    /**
     * PUT/PATCH /centros-costo/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $cc = CentroCosto::findOrFail($id);

        // Para el unique: si el request trae parent_id lo usa; si no, mantiene el actual
        $parentIdParaUnique = $request->has('parent_id')
            ? ($request->input('parent_id') ?: null)
            : $cc->parent_id;

        $validated = $request->validate([
            'codigo' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('centros_costo')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('parent_id', $parentIdParaUnique)),
            ],
            'nombre'      => 'sometimes|string|max:100',
            'sucursal_id' => 'sometimes|nullable|string|exists:sucursales,id',
            'parent_id'   => 'sometimes|nullable|string|exists:centros_costo,id',
            'activo'      => 'boolean',
        ]);

        // Recalcular nivel si cambia el padre
        if (array_key_exists('parent_id', $validated)) {
            if (!empty($validated['parent_id'])) {
                // No permitir asignarse como propio padre
                if ($validated['parent_id'] === $id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Un centro de costo no puede ser su propio padre.',
                    ], 422);
                }

                $parent = CentroCosto::findOrFail($validated['parent_id']);
                $nuevoNivel = $parent->nivel + 1;

                if ($nuevoNivel > 3) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Máximo 3 niveles de jerarquía.',
                    ], 422);
                }

                $validated['nivel'] = $nuevoNivel;
            } else {
                $validated['nivel'] = 1; // Sube a raíz
            }
        }

        $cc->update($validated);
        $cc->load(['sucursal:id,nombre', 'parent:id,codigo,nombre']);

        return response()->json(['success' => true, 'data' => $cc]);
    }

    /**
     * DELETE /centros-costo/{id}
     * Desactiva en lugar de borrar (preserva integridad referencial).
     * No puede desactivarse si tiene hijos activos.
     */
    public function destroy(string $id): JsonResponse
    {
        $cc = CentroCosto::withCount(['children' => fn ($q) => $q->where('activo', true)])
                         ->findOrFail($id);

        if ($cc->children_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "No se puede desactivar: tiene {$cc->children_count} subcentro(s) activo(s). Desactívalos primero.",
            ], 422);
        }

        $cc->update(['activo' => false]);

        return response()->json(['success' => true, 'data' => null]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Construye árbol anidado a partir de lista plana. */
    private function buildTree(array $items, ?string $parentId = null): array
    {
        $tree = [];

        foreach ($items as $item) {
            $itemParent = $item['parent_id'] ?? null;
            if ($itemParent === $parentId) {
                $item['children'] = $this->buildTree($items, $item['id']);
                $tree[] = $item;
            }
        }

        return $tree;
    }
}
