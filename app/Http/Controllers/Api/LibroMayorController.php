<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LibroMayor\LibroMayorRequest;
use App\Services\LibroMayor\LibroMayorService;
use Illuminate\Http\JsonResponse;

/**
 * GET /libro-mayor/{cuenta_contable_id}
 *
 * Devuelve:
 *   - Saldos agregados (SI, movimientos, SF) por los filtros dados
 *   - Movimientos cronológicos con saldo acumulado por línea (paginado)
 *
 * Rate-limit: 60/min (heredado del grupo auth:sanctum).
 * Cache: 30 min, invalidado por AsientoAprobado del periodo consultado.
 */
class LibroMayorController extends Controller
{
    public function __construct(
        private readonly LibroMayorService $service,
    ) {}

    public function __invoke(LibroMayorRequest $request, string $cuentaId): JsonResponse
    {
        $filtros = array_filter([
            'periodo_id'      => $request->validated('periodo_id'),
            'tercero_id'      => $request->validated('tercero_id'),
            'centro_costo_id' => $request->validated('centro_costo_id'),
            'sucursal_id'     => $request->validated('sucursal_id'),
            'desde'           => $request->validated('desde'),
            'hasta'           => $request->validated('hasta'),
            'page'            => $request->validated('page'),
            'per_page'        => $request->validated('per_page'),
        ], fn (mixed $v): bool => $v !== null);

        $resultado = $this->service->query($cuentaId, $filtros);

        return response()->json([
            'success' => true,
            'data'    => [
                'cuenta'      => $resultado->cuenta,
                'filtros'     => $resultado->filtros,
                'saldos'      => $resultado->saldos,
                'movimientos' => array_map(
                    fn (object $m): array => (array) $m,
                    $resultado->movimientos,
                ),
                'paginacion'  => $resultado->paginacion,
            ],
        ]);
    }
}
