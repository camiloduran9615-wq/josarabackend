<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\BalanceGeneralRequest;
use App\Services\Reportes\BalanceGeneralService;
use App\Services\Reportes\DTOs\EcuacionBalanceDto;
use App\Services\Reportes\DTOs\GrupoBalanceDto;
use App\Services\Reportes\DTOs\SeccionBalanceDto;
use App\Services\Reportes\DTOs\SeccionTotalDto;
use Illuminate\Http\JsonResponse;

/**
 * GET /balance-general?fecha_corte=YYYY-MM-DD&comparativo_año_anterior=true
 *
 * Genera el Balance General (Estado de Situación Financiera — NIC 1).
 * Cache 1 hora, invalidado por AprobacionAsiento en cualquier cuenta de clase 1-3.
 */
class BalanceGeneralController extends Controller
{
    public function __construct(
        private readonly BalanceGeneralService $service,
    ) {}

    public function __invoke(BalanceGeneralRequest $request): JsonResponse
    {
        $fechaCorte  = (string) $request->validated('fecha_corte');
        $comparativo = (bool)   ($request->validated('comparativo_año_anterior') ?? false);

        $dto = $this->service->generate($fechaCorte, $comparativo);

        return response()->json([
            'success' => true,
            'data'    => [
                'fecha_corte'       => $dto->fechaCorte,
                'fecha_comparativo' => $dto->fechaComparativo,
                'moneda'            => $dto->moneda,
                'tenant'            => [
                    'razon_social' => $dto->tenantRazonSocial,
                    'nit'          => $dto->tenantNit,
                ],
                // Estructura UI-friendly esperada por BalanceGeneralPage.tsx
                // (cada sección expone bloques 'corriente' / 'no_corriente' con cuentas planas)
                'activo'     => $this->serializarParaUI($dto->activo, true),
                'pasivo'     => $this->serializarParaUI($dto->pasivo, true),
                'patrimonio' => $this->serializarPatrimonioParaUI($dto->patrimonio),
                'ecuacion_valida' => $dto->ecuacion->balanceado,
                'ecuacion'   => $this->serializarEcuacion($dto->ecuacion),
                'meta'       => [
                    'generado_at' => $dto->generadoAt,
                    'tiempo_ms'   => $dto->tiempoMs,
                    'ms'          => $dto->tiempoMs,
                    'cached'      => $dto->cached,
                ],
            ],
        ]);
    }

    /**
     * Aplana una SeccionTotal en bloques 'corriente' / 'no_corriente' con cuentas planas.
     * Si solo hay una sub-sección la trata como 'corriente' y devuelve no_corriente vacío.
     */
    private function serializarParaUI(SeccionTotalDto $st, bool $separarCorriente): array
    {
        $bloques = ['corriente' => null, 'no_corriente' => null];

        foreach ($st->subsecciones as $sub) {
            $bloque = [
                'titulo'  => $sub->nombre,
                'total'   => (float) $sub->total,
                'cuentas' => $this->aplanarCuentas($sub->grupos),
            ];
            $esNoCorriente = stripos($sub->nombre, 'No Corriente') !== false;
            $key = $esNoCorriente ? 'no_corriente' : 'corriente';
            // Si ya hay algo en la slot, acumula (caso edge de múltiples subsecciones)
            if ($bloques[$key] === null) {
                $bloques[$key] = $bloque;
            } else {
                $bloques[$key]['total']   += $bloque['total'];
                $bloques[$key]['cuentas']  = array_merge($bloques[$key]['cuentas'], $bloque['cuentas']);
            }
        }

        // Asegurar que ambos bloques existan (frontend itera siempre)
        if ($bloques['corriente'] === null) {
            $bloques['corriente'] = ['titulo' => 'Corriente', 'total' => 0.0, 'cuentas' => []];
        }
        if ($bloques['no_corriente'] === null) {
            $bloques['no_corriente'] = ['titulo' => 'No Corriente', 'total' => 0.0, 'cuentas' => []];
        }

        return [
            'total'          => (float) $st->total,
            'corriente'      => $bloques['corriente'],
            'no_corriente'   => $bloques['no_corriente'],
        ];
    }

    /**
     * Patrimonio se expone como un bloque directo (sin sub-clasificación corriente).
     */
    private function serializarPatrimonioParaUI(SeccionTotalDto $st): array
    {
        $cuentas = [];
        foreach ($st->subsecciones as $sub) {
            $cuentas = array_merge($cuentas, $this->aplanarCuentas($sub->grupos));
        }
        return [
            'titulo'  => 'Patrimonio',
            'total'   => (float) $st->total,
            'cuentas' => $cuentas,
        ];
    }

    /** Aplana los grupos en un array plano de {codigo, nombre, saldo}. */
    private function aplanarCuentas(array $grupos): array
    {
        $out = [];
        foreach ($grupos as $g) {
            foreach ($g->cuentas as $c) {
                $arr = (array) $c;
                // Buscar el saldo entre los campos camelCase / snake_case posibles
                $saldo = $arr['saldo']
                      ?? $arr['saldoActual']
                      ?? $arr['saldo_actual']
                      ?? $arr['total']
                      ?? 0;
                $out[] = [
                    'codigo' => $arr['codigo'] ?? '',
                    'nombre' => $arr['nombre'] ?? '',
                    'saldo'  => (float) $saldo,
                ];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function serializarSeccionTotal(SeccionTotalDto $st): array
    {
        return [
            'total'          => $st->total,
            'total_anterior' => $st->totalAnterior,
            'subsecciones'   => array_map(
                fn (SeccionBalanceDto $s): array => $this->serializarSeccion($s),
                $st->subsecciones,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function serializarSeccion(SeccionBalanceDto $s): array
    {
        return [
            'nombre'         => $s->nombre,
            'total'          => $s->total,
            'total_anterior' => $s->totalAnterior,
            'grupos'         => array_map(
                fn (GrupoBalanceDto $g): array => [
                    'codigo'         => $g->codigo,
                    'nombre'         => $g->nombre,
                    'total'          => $g->total,
                    'total_anterior' => $g->totalAnterior,
                    'cuentas'        => array_map(
                        fn (object $c): array => (array) $c,
                        $g->cuentas,
                    ),
                ],
                $s->grupos,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function serializarEcuacion(EcuacionBalanceDto $e): array
    {
        return [
            'activo'               => $e->activo,
            'pasivo_mas_patrimonio' => $e->pasivoMasPatrimonio,
            'diferencia'           => $e->diferencia,
            'balanceado'           => $e->balanceado,
        ];
    }
}
