<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\EstadoResultadosRequest;
use App\Services\Reportes\DTOs\BloqueEstadoResultadosDto;
use App\Services\Reportes\EstadoResultadosService;
use Illuminate\Http\JsonResponse;

/**
 * GET /estado-resultados?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&comparativo=true
 *
 * P&G por función (NIC 1 párr. 103).
 * Cache 1 hora por [desde, hasta, comparativo].
 */
class EstadoResultadosController extends Controller
{
    public function __construct(
        private readonly EstadoResultadosService $service,
    ) {}

    public function __invoke(EstadoResultadosRequest $request): JsonResponse
    {
        $desde       = (string) $request->validated('desde');
        $hasta       = (string) $request->validated('hasta');
        $comparativo = (bool)   ($request->validated('comparativo') ?? false);

        $dto = $this->service->generate($desde, $hasta, $comparativo);

        $bloques = [
            $this->bloqueUI($dto->ingresos),
            $this->bloqueUI($dto->costoVentas),
            $this->bloqueUI($dto->gastosOperacionales),
            $this->bloqueUI($dto->otrosIngresosEgresos),
            $this->bloqueUI($dto->impuestoRenta),
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'desde'             => $dto->desde,
                'hasta'             => $dto->hasta,
                'desde_comparativo' => $dto->desdeComparativo,
                'hasta_comparativo' => $dto->hastaComparativo,
                'moneda'            => $dto->moneda,
                'tenant'            => [
                    'razon_social' => $dto->tenantRazonSocial,
                    'nit'          => $dto->tenantNit,
                ],
                // Estructura UI-friendly esperada por EstadoResultadosPage.tsx
                'bloques'                  => $bloques,
                'utilidad_bruta'           => (float) $dto->utilidadBruta,
                'utilidad_bruta_comparativo'=> $dto->utilidadBrutaComparativa !== null ? (float) $dto->utilidadBrutaComparativa : null,
                'utilidad_operacional'     => (float) $dto->utilidadOperacional,
                'utilidad_operacional_comparativo' => $dto->utilidadOperacionalComparativa !== null ? (float) $dto->utilidadOperacionalComparativa : null,
                'utilidad_antes_impuesto'  => (float) $dto->utilidadAntesImpuesto,
                'utilidad_antes_impuesto_comparativo' => $dto->utilidadAntesImpuestoComparativa !== null ? (float) $dto->utilidadAntesImpuestoComparativa : null,
                'utilidad_neta'            => (float) $dto->utilidadNeta,
                'utilidad_neta_comparativo'=> $dto->utilidadNetaComparativa !== null ? (float) $dto->utilidadNetaComparativa : null,
                // Crudo (compatibilidad con consumidores antiguos)
                'ingresos'              => $this->serializarBloque($dto->ingresos),
                'costo_ventas'          => $this->serializarBloque($dto->costoVentas),
                'gastos_operacionales'  => $this->serializarBloque($dto->gastosOperacionales),
                'otros_ingresos_egresos'=> $this->serializarBloque($dto->otrosIngresosEgresos),
                'impuesto_renta'        => $this->serializarBloque($dto->impuestoRenta),
                'meta'                  => [
                    'generado_at' => $dto->generadoAt,
                    'tiempo_ms'   => $dto->tiempoMs,
                    'ms'          => $dto->tiempoMs,
                    'cached'      => $dto->cached,
                ],
            ],
        ]);
    }

    /**
     * Aplana un BloqueEstadoResultadosDto al shape que consume el frontend:
     * { titulo, total, total_comparativo, cuentas: [{codigo, nombre, saldo, saldo_comparativo}] }
     *
     * @return array<string, mixed>
     */
    private function bloqueUI(BloqueEstadoResultadosDto $b): array
    {
        return [
            'titulo'            => $b->nombre,
            'codigo'            => $b->codigo,
            'total'             => (float) $b->total,
            'total_comparativo' => $b->totalComparativo !== null ? (float) $b->totalComparativo : null,
            'cuentas'           => array_map(
                fn (object $l): array => [
                    'codigo'             => $l->codigo ?? '',
                    'nombre'             => $l->nombre ?? '',
                    'saldo'              => (float) ($l->saldo ?? 0),
                    'saldo_comparativo'  => isset($l->saldoComparativo) && $l->saldoComparativo !== null
                        ? (float) $l->saldoComparativo
                        : null,
                ],
                $b->lineas,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function serializarBloque(BloqueEstadoResultadosDto $b): array
    {
        return [
            'codigo'           => $b->codigo,
            'nombre'           => $b->nombre,
            'total'            => $b->total,
            'total_comparativo'=> $b->totalComparativo,
            'lineas'           => array_map(
                fn (object $l): array => (array) $l,
                $b->lineas,
            ),
        ];
    }
}
