<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\BalanceComprobacionRequest;
use App\Services\Reportes\BalanceComprobacionService;
use App\Services\Reportes\DTOs\ValidacionBalanceComprobacionDto;
use Illuminate\Http\JsonResponse;

/**
 * GET /balance-comprobacion?periodo_id=UUID&nivel=1
 *
 * Balance de Comprobación (12 columnas) con las 4 validaciones de igualdad.
 * Cache 30 min (periodos activos reciben asientos frecuentemente).
 */
class BalanceComprobacionController extends Controller
{
    public function __construct(
        private readonly BalanceComprobacionService $service,
    ) {}

    public function __invoke(BalanceComprobacionRequest $request): JsonResponse
    {
        $periodoId = (string) $request->validated('periodo_id');
        $nivel     = (int)    ($request->validated('nivel') ?? 1);

        $dto = $this->service->generate($periodoId, $nivel);

        // Convierte filas camelCase → snake_case + agrega totales acumulados
        $filas    = [];
        $totales  = [
            'saldo_inicial_debito'   => 0.0, 'saldo_inicial_credito'   => 0.0,
            'movimiento_debito'      => 0.0, 'movimiento_credito'      => 0.0,
            'saldo_final_debito'     => 0.0, 'saldo_final_credito'     => 0.0,
            'ajuste_debito'          => 0.0, 'ajuste_credito'          => 0.0,
            'saldo_ajustado_debito'  => 0.0, 'saldo_ajustado_credito'  => 0.0,
        ];

        foreach ($dto->filas as $f) {
            $fila = [
                'codigo'                  => $f->codigo,
                'nombre'                  => $f->nombre,
                'naturaleza'              => $f->naturaleza,
                'saldo_inicial_debito'    => (float) $f->saldoInicialDebito,
                'saldo_inicial_credito'   => (float) $f->saldoInicialCredito,
                'movimiento_debito'       => (float) $f->movimientoDebito,
                'movimiento_credito'      => (float) $f->movimientoCredito,
                'saldo_final_debito'      => (float) $f->saldoFinalDebito,
                'saldo_final_credito'     => (float) $f->saldoFinalCredito,
                'ajuste_debito'           => (float) $f->ajusteDebito,
                'ajuste_credito'          => (float) $f->ajusteCredito,
                'saldo_ajustado_debito'   => (float) $f->saldoAjustadoDebito,
                'saldo_ajustado_credito'  => (float) $f->saldoAjustadoCredito,
            ];
            $filas[] = $fila;
            foreach ($totales as $k => $_) {
                $totales[$k] += $fila[$k];
            }
        }

        $v = $dto->validacion;
        // Validaciones que espera el frontend (claves snake_case esperadas)
        $validaciones = [
            'saldo_inicial_igual'                  => $v->siBalanceado,
            'movimientos_iguales'                  => $v->movBalanceado,
            'saldo_final_igual'                    => $v->saBalanceado,
            // Activo = Pasivo + Patrimonio: válido si saldo final cuadra (proxy fiable)
            'ecuacion_activo_pasivo_patrimonio'    => $v->saBalanceado,
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'periodo'      => [
                    'codigo' => $dto->periodoCodigo,
                    'nombre' => $dto->periodoNombre,
                    'desde'  => $dto->desde,
                    'hasta'  => $dto->hasta,
                ],
                'moneda'       => $dto->moneda,
                'tenant'       => [
                    'razon_social' => $dto->tenantRazonSocial,
                    'nit'          => $dto->tenantNit,
                ],
                'nivel'        => $dto->nivel,
                'filas'        => $filas,
                'totales'      => $totales,
                'validaciones' => $validaciones,
                // Mantengo `validacion` (singular, original) para retro-compatibilidad
                'validacion'   => $this->serializarValidacion($v),
                'meta'         => [
                    'generado_at' => $dto->generadoAt,
                    'tiempo_ms'   => $dto->tiempoMs,
                    'ms'          => $dto->tiempoMs,    // alias para frontend
                    'cached'      => $dto->cached,
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serializarValidacion(ValidacionBalanceComprobacionDto $v): array
    {
        return [
            'saldo_inicial'  => ['debito' => $v->totalSiDebito,  'credito' => $v->totalSiCredito,  'delta' => $v->deltaSi,  'balanceado' => $v->siBalanceado],
            'movimientos'    => ['debito' => $v->totalMovDebito, 'credito' => $v->totalMovCredito, 'delta' => $v->deltaMov, 'balanceado' => $v->movBalanceado],
            'ajustes'        => ['debito' => $v->totalAjDebito,  'credito' => $v->totalAjCredito,  'delta' => $v->deltaAj,  'balanceado' => $v->ajBalanceado],
            'saldo_ajustado' => ['debito' => $v->totalSaDebito,  'credito' => $v->totalSaCredito,  'delta' => $v->deltaSa,  'balanceado' => $v->saBalanceado],
            'valido'         => $v->valido,
        ];
    }
}
