<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ContratoLaboral;
use App\Models\Tenant\Empleado;
use App\Models\Tenant\LiquidacionNomina;
use App\Models\Tenant\PeriodoNomina;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use App\Services\Nomina\AsientoNominaService;
use App\Services\Nomina\LiquidacionNominaService;
use App\Services\Nomina\NominaDianXmlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nómina Electrónica DIAN — CRUD de empleados, contratos, periodos y liquidaciones.
 *
 * Endpoints:
 *   GET    /empleados
 *   POST   /empleados
 *   PUT    /empleados/{id}
 *   DELETE /empleados/{id}
 *
 *   GET    /contratos?empleado_id=
 *   POST   /contratos
 *
 *   GET    /periodos-nomina
 *   POST   /periodos-nomina
 *
 *   GET    /liquidaciones?periodo_id=
 *   POST   /liquidaciones/{empleado_id}/{periodo_id}   ← liquida un empleado
 *   GET    /liquidaciones/{id}/xml                      ← genera XML DIAN
 *   POST   /liquidaciones/{id}/aprobar
 */
class NominaController extends Controller
{
    public function __construct(
        private readonly LiquidacionNominaService $liquidador,
        private readonly NominaDianXmlService $xmlService,
        private readonly AsientoNominaService $asientoNomina,
    ) {}

    // ── Empleados ──────────────────────────────────────────────────────────

    public function empleadosIndex(Request $request): JsonResponse
    {
        $empleados = Empleado::query()
            ->when($request->boolean('solo_activos', true), fn ($q) => $q->where('activo', true))
            ->with('contratos')
            ->orderBy('primer_apellido')
            ->get();

        return response()->json(['success' => true, 'data' => $empleados]);
    }

    public function empleadosStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_documento'    => ['required', 'string', 'max:10'],
            'numero_documento'  => ['required', 'string', 'max:20', 'unique:empleados,numero_documento'],
            'primer_nombre'     => ['required', 'string', 'max:80'],
            'segundo_nombre'    => ['nullable', 'string', 'max:80'],
            'primer_apellido'   => ['required', 'string', 'max:80'],
            'segundo_apellido'  => ['nullable', 'string', 'max:80'],
            'email'             => ['nullable', 'email'],
            'telefono'          => ['nullable', 'string', 'max:20'],
            'banco'             => ['nullable', 'string', 'max:80'],
            'tipo_cuenta'       => ['nullable', 'string', 'max:20'],
            'numero_cuenta'     => ['nullable', 'string', 'max:30'],
        ]);

        $empleado = Empleado::create($validated);

        return response()->json(['success' => true, 'data' => $empleado], Response::HTTP_CREATED);
    }

    public function empleadosUpdate(Request $request, string $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);

        $validated = $request->validate([
            'primer_nombre'   => ['sometimes', 'string', 'max:80'],
            'segundo_nombre'  => ['nullable', 'string', 'max:80'],
            'primer_apellido' => ['sometimes', 'string', 'max:80'],
            'segundo_apellido'=> ['nullable', 'string', 'max:80'],
            'email'           => ['nullable', 'email'],
            'telefono'        => ['nullable', 'string', 'max:20'],
            'banco'           => ['nullable', 'string', 'max:80'],
            'tipo_cuenta'     => ['nullable', 'string', 'max:20'],
            'numero_cuenta'   => ['nullable', 'string', 'max:30'],
            'activo'          => ['sometimes', 'boolean'],
        ]);

        $empleado->update($validated);

        return response()->json(['success' => true, 'data' => $empleado]);
    }

    public function empleadosDestroy(string $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);
        $empleado->update(['activo' => false]);
        $empleado->delete();

        return response()->json(['success' => true]);
    }

    // ── Contratos ─────────────────────────────────────────────────────────

    public function contratosIndex(Request $request): JsonResponse
    {
        $contratos = ContratoLaboral::query()
            ->when($request->filled('empleado_id'), fn ($q) => $q->where('empleado_id', $request->empleado_id))
            ->with('empleado')
            ->orderByDesc('fecha_inicio')
            ->get();

        return response()->json(['success' => true, 'data' => $contratos]);
    }

    public function contratosStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'empleado_id'      => ['required', 'uuid', 'exists:empleados,id'],
            'tipo_contrato'    => ['required', 'string', 'in:indefinido,fijo,obra_labor,aprendizaje'],
            'tipo_trabajador'  => ['required', 'string'],
            'fecha_inicio'     => ['required', 'date'],
            'fecha_fin'        => ['nullable', 'date', 'after:fecha_inicio'],
            'salario_basico'   => ['required', 'numeric', 'min:1300000'],
            'dias_trabajo'     => ['sometimes', 'integer', 'min:1', 'max:30'],
            'cargo'            => ['nullable', 'string', 'max:100'],
            'departamento'     => ['nullable', 'string', 'max:100'],
            'alto_riesgo'      => ['sometimes', 'boolean'],
        ]);

        // Inactivar contrato anterior del mismo empleado
        ContratoLaboral::where('empleado_id', $validated['empleado_id'])
            ->where('activo', true)
            ->update(['activo' => false]);

        $contrato = ContratoLaboral::create(array_merge($validated, ['activo' => true]));

        return response()->json(['success' => true, 'data' => $contrato], Response::HTTP_CREATED);
    }

    // ── Periodos de nómina ────────────────────────────────────────────────

    public function periodosNominaIndex(Request $request): JsonResponse
    {
        $periodos = PeriodoNomina::orderByDesc('fecha_inicio')
            ->limit(24)
            ->get();

        return response()->json(['success' => true, 'data' => $periodos]);
    }

    public function periodosNominaStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo'         => ['required', 'in:mensual,quincenal'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin'    => ['required', 'date', 'after:fecha_inicio'],
            'año'          => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes'          => ['required', 'integer', 'min:1', 'max:12'],
            'quincena'     => ['nullable', 'integer', 'in:1,2'],
        ]);

        $quincena = $validated['quincena'] ?? null;
        $codigo = $validated['año']
            . str_pad((string) $validated['mes'], 2, '0', STR_PAD_LEFT)
            . ($quincena ? '-Q' . $quincena : '');

        $periodo = PeriodoNomina::create(array_merge($validated, ['codigo' => $codigo]));

        return response()->json(['success' => true, 'data' => $periodo], Response::HTTP_CREATED);
    }

    // ── Liquidaciones ─────────────────────────────────────────────────────

    public function liquidacionesIndex(Request $request): JsonResponse
    {
        $liq = LiquidacionNomina::query()
            ->when($request->filled('periodo_id'), fn ($q) => $q->where('periodo_nomina_id', $request->periodo_id))
            ->with(['empleado', 'periodo', 'nominaDian'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $liq]);
    }

    public function liquidar(Request $request, string $empleadoId, string $periodoId): JsonResponse
    {
        $empleado = Empleado::findOrFail($empleadoId);
        $periodo  = PeriodoNomina::findOrFail($periodoId);
        $contrato = $empleado->contratoActivo();

        if ($contrato === null) {
            return response()->json([
                'success' => false,
                'message' => "El empleado {$empleado->primer_nombre} no tiene contrato activo.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $extras = $request->only(['horas_extra_diurnas', 'horas_extra_nocturnas', 'bonificacion', 'comision', 'embargo', 'libranza']);

        $liquidacion = $this->liquidador->liquidar($empleado, $periodo, $contrato, $extras);

        return response()->json(['success' => true, 'data' => $liquidacion->load('lineas.concepto')], Response::HTTP_CREATED);
    }

    public function generarXml(string $id): JsonResponse
    {
        $liq = LiquidacionNomina::with(['empleado', 'contrato', 'periodo', 'lineas.concepto'])->findOrFail($id);

        $nominaDian = $this->xmlService->generar($liq);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'               => $nominaDian->id,
                'cune'             => $nominaDian->cune,
                'numero_documento' => $nominaDian->numero_documento,
                'estado_dian'      => $nominaDian->estado_dian,
                'xml_preview'      => substr((string) $nominaDian->xml_generado, 0, 500) . '...',
            ],
        ]);
    }

    public function aprobar(string $id): JsonResponse
    {
        $liq = LiquidacionNomina::findOrFail($id);

        if ($liq->estado !== 'borrador') {
            return response()->json([
                'success' => false,
                'message' => "La liquidación ya está en estado: {$liq->estado}",
            ], Response::HTTP_CONFLICT);
        }

        // Atomicidad: marcar 'aprobado' y generar el asiento contable en la
        // misma transacción. Si la parametrización contable está incompleta,
        // fallamos con 422 sin marcar como aprobado para evitar inconsistencia.
        try {
            DB::transaction(function () use ($liq): void {
                $liq->update(['estado' => 'aprobado']);
                $this->asientoNomina->generar(
                    $liq,
                    (string) (auth()->id() ?? $liq->empleado_id),
                );
            });
        } catch (ParametrizacionFaltanteException $e) {
            Log::warning('NominaController::aprobar — parametrización incompleta', [
                'liquidacion_id' => $liq->id,
                'error'          => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se puede aprobar: ' . $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['success' => true, 'data' => $liq->fresh()]);
    }
}
