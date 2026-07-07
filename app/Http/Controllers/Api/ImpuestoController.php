<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Impuesto\CalcularImpuestoRequest;
use App\Http\Requests\Impuesto\StoreImpuestoRequest;
use App\Http\Requests\Impuesto\UpdateImpuestoRequest;
use App\Http\Resources\ImpuestoResource;
use App\Models\Tenant\Impuesto;
use App\Services\Impuesto\ImpuestoCalculadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD de catálogo de impuestos + endpoint de cálculo.
 *
 * Endpoints:
 *   GET    /impuestos                  → index (filtros: tipo, activa, aplica_compras)
 *   POST   /impuestos                  → store
 *   GET    /impuestos/{id}             → show
 *   PUT    /impuestos/{id}             → update (solo si !sistema)
 *   DELETE /impuestos/{id}             → destroy (solo admin, !sistema)
 *   POST   /impuestos/calcular         → calcular (no persiste)
 */
class ImpuestoController extends Controller
{
    public function __construct(
        private readonly ImpuestoCalculadorService $calculador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Impuesto::class);

        $query = Impuesto::query()->orderBy('tipo')->orderBy('codigo');

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }
        if ($request->query('activa') !== null) {
            $query->where('activa', filter_var($request->query('activa'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->query('aplica_compras') !== null) {
            $query->where('aplica_compras', filter_var($request->query('aplica_compras'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->query('aplica_ventas') !== null) {
            $query->where('aplica_ventas', filter_var($request->query('aplica_ventas'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->query('vigente') !== null) {
            $query->vigentes();
        }

        return response()->json([
            'success' => true,
            'data'    => ImpuestoResource::collection($query->get()),
        ]);
    }

    public function store(StoreImpuestoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sistema']            = false;
        $data['created_by_user_id'] = $request->user()?->id;

        $impuesto = Impuesto::query()->create($data);

        return response()->json([
            'success' => true,
            'data'    => new ImpuestoResource($impuesto),
        ], Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $impuesto = Impuesto::query()->findOrFail($id);
        $this->authorize('view', $impuesto);

        return response()->json([
            'success' => true,
            'data'    => new ImpuestoResource($impuesto),
        ]);
    }

    public function update(UpdateImpuestoRequest $request, Impuesto $impuesto): JsonResponse
    {
        $impuesto->update($request->validated());

        return response()->json([
            'success' => true,
            'data'    => new ImpuestoResource($impuesto->refresh()),
        ]);
    }

    public function destroy(Request $request, Impuesto $impuesto): JsonResponse
    {
        $this->authorize('delete', $impuesto);

        $impuesto->delete();

        return response()->json(['success' => true], Response::HTTP_NO_CONTENT);
    }

    public function calcular(CalcularImpuestoRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $fecha = isset($validated['fecha'])
                ? new \DateTimeImmutable((string) $validated['fecha'])
                : null;

            $resultado = $this->calculador->calcular(
                base:            (string) $validated['base'],
                codigoImpuesto:  (string) $validated['codigo_impuesto'],
                fecha:           $fecha,
                municipioDane:   $validated['municipio_dane'] ?? null,
                actividadCiiu:   $validated['actividad_ciiu'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'codigo'                  => $resultado->codigo,
                'nombre'                  => $resultado->nombre,
                'tipo'                    => $resultado->tipo,
                'base'                    => $resultado->base,
                'tarifa_porcentaje'       => $resultado->tarifaPorcentaje,
                'impuesto_calculado'      => $resultado->impuestoCalculado,
                'base_minima_uvt'         => $resultado->baseMinimaUvt,
                'base_minima_aplicada_cop'=> $resultado->baseMinimaAplicadaCop,
                'base_bajo_umbral'        => $resultado->baseBajoUmbral,
                'cuenta_contable_id'      => $resultado->cuentaContableId,
                'cuenta_contrapartida_id' => $resultado->cuentaContrapartidaId,
            ],
        ]);
    }
}
