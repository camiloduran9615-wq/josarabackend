<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CierreAnual\EjecutarCierreAnualRequest;
use App\Services\Periodo\CierreAnualService;
use App\Services\Periodo\PeriodoOperacionInvalidaException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /cierre-anual/{año}
 *
 * Ejecuta el cierre fiscal anual: genera asientos de cancelación de cuentas
 * de resultado (clases 4/5/6/7 → 5905) y traslado a patrimonio (5905 → 3606).
 *
 * Prerrequisitos:
 *   1. El periodo anual para `año` debe existir en estado 'cerrado'
 *   2. Deben existir cuentas 5905 y 3606 en el PUC
 *   3. Solo contador/admin pueden ejecutarlo
 *
 * Idempotente: si ya se ejecutó, retorna 409 Conflict.
 * Rate-limit estricto: 3/minuto (operación irreversible).
 */
class CierreAnualController extends Controller
{
    public function __construct(
        private readonly CierreAnualService $service,
    ) {}

    public function __invoke(EjecutarCierreAnualRequest $request, int $anio): JsonResponse
    {
        if ($anio < 2000 || $anio > 2100) {
            return response()->json([
                'success' => false,
                'message' => "Año fiscal inválido: {$anio}. Debe estar entre 2000 y 2100.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var \App\Models\User $contador */
        $contador = $request->user();

        try {
            $resultado = $this->service->ejecutar($anio, $contador);
        } catch (PeriodoOperacionInvalidaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'anio'                   => $resultado['anio'],
                'resultado'              => $resultado['resultado'],
                'monto'                  => $resultado['monto'],
                'asiento_cancelacion_id' => $resultado['asiento_cancelacion_id'],
                'asiento_traslado_id'    => $resultado['asiento_traslado_id'],
                'mensaje'                => match ($resultado['resultado']) {
                    'utilidad'   => "Cierre anual {$resultado['anio']} ejecutado. Utilidad del ejercicio: COP {$resultado['monto']}.",
                    'perdida'    => "Cierre anual {$resultado['anio']} ejecutado. Pérdida del ejercicio: COP {$resultado['monto']}.",
                    default      => "Cierre anual {$resultado['anio']} ejecutado. Resultado en equilibrio.",
                },
            ],
        ], Response::HTTP_CREATED);
    }
}
