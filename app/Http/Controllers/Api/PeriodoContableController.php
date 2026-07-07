<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\Periodo\PeriodoBloqueadoFiscal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Periodo\ClosePeriodoRequest;
use App\Http\Requests\Periodo\ReopenApproveRequest;
use App\Http\Requests\Periodo\ReopenRequestRequest;
use App\Http\Resources\PeriodoResource;
use App\Models\Tenant\PeriodoContable;
use App\Services\Periodo\CerrarPeriodoService;
use App\Services\Periodo\PeriodoOperacionInvalidaException;
use App\Services\Periodo\PreCierreFallidoException;
use App\Services\Periodo\ReabrirPeriodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PeriodoContableController extends Controller
{
    public function __construct(
        private readonly CerrarPeriodoService $cerrar,
        private readonly ReabrirPeriodoService $reabrir,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PeriodoContable::class);

        $query = PeriodoContable::query();
        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }
        if ($año = $request->query('año_fiscal')) {
            $query->where('año_fiscal', (int) $año);
        }
        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }
        $query->orderByDesc('fecha_inicio');

        return response()->json([
            'success' => true,
            'data'    => PeriodoResource::collection($query->get()),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $periodo = PeriodoContable::query()->findOrFail($id);
        $this->authorize('view', $periodo);

        return response()->json([
            'success' => true,
            'data'    => new PeriodoResource($periodo),
        ]);
    }

    public function checklistCierre(string $id): JsonResponse
    {
        $periodo = PeriodoContable::query()->findOrFail($id);
        $this->authorize('view', $periodo);

        $checklist = $this->cerrar->ejecutarChecklist($periodo);
        $puedeCerrar = collect($checklist)->every(
            fn (array $item): bool => ($item['ok'] ?? null) !== false
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'periodo'      => new PeriodoResource($periodo),
                'puede_cerrar' => $puedeCerrar,
                'checklist'    => $checklist,
            ],
        ]);
    }

    public function cerrar(ClosePeriodoRequest $request, string $id): JsonResponse
    {
        $periodo = PeriodoContable::query()->findOrFail($id);

        try {
            $cerrado = $this->cerrar->ejecutar(
                $periodo,
                $request->user(),
                (string) ($request->validated('motivo') ?? '') ?: null,
            );
        } catch (PreCierreFallidoException $e) {
            return response()->json([
                'success'   => false,
                'message'   => $e->getMessage(),
                'checklist' => $e->checklist,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PeriodoOperacionInvalidaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'data'    => new PeriodoResource($cerrado),
        ]);
    }

    public function solicitarReapertura(ReopenRequestRequest $request, string $id): JsonResponse
    {
        $periodo = PeriodoContable::query()->findOrFail($id);

        try {
            $req = $this->reabrir->solicitar(
                $periodo,
                $request->user(),
                (string) $request->validated('motivo'),
            );
        } catch (PeriodoOperacionInvalidaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'request_id' => $req->id,
                'expires_at' => $req->expires_at?->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    public function aprobarReapertura(ReopenApproveRequest $request, string $id): JsonResponse
    {
        try {
            $periodo = $this->reabrir->aprobar(
                (string) $request->validated('request_id'),
                $request->user(),
            );
        } catch (PeriodoOperacionInvalidaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'data'    => new PeriodoResource($periodo),
        ]);
    }

    public function bloquearFiscal(Request $request, string $id): JsonResponse
    {
        $periodo = PeriodoContable::query()->findOrFail($id);
        $this->authorize('lockFiscal', $periodo);

        if ($periodo->estado !== PeriodoContable::ESTADO_CERRADO) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden bloquear fiscalmente periodos cerrados.',
            ], Response::HTTP_CONFLICT);
        }

        $periodo->update([
            'estado'                  => PeriodoContable::ESTADO_BLOQUEADO_FISCAL,
            'bloqueado_fiscal_por_id' => $request->user()->id,
            'bloqueado_fiscal_at'     => now(),
        ]);

        event(new PeriodoBloqueadoFiscal($periodo->fresh(), $request->user()));

        return response()->json([
            'success' => true,
            'data'    => new PeriodoResource($periodo->fresh()),
        ]);
    }
}
