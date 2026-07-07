<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Conciliacion\ConciliacionBancariaService;
use App\Services\Conciliacion\ReporteConciliacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conciliación Bancaria.
 *
 * GET    /extractos-bancarios                  ← lista extractos
 * POST   /extractos-bancarios/importar         ← sube CSV multipart
 * GET    /extractos-bancarios/{id}/lineas      ← líneas del extracto
 * POST   /extractos-bancarios/{id}/conciliar-auto
 * POST   /extractos-bancarios/{id}/conciliar-manual
 */
class ConciliacionBancariaController extends Controller
{
    public function __construct(
        private readonly ConciliacionBancariaService $service,
        private readonly ReporteConciliacionService $reporte,
    ) {}

    public function index(): JsonResponse
    {
        $extractos = DB::table('extractos_bancarios')
            ->orderByDesc('periodo_inicio')
            ->get();

        return response()->json(['success' => true, 'data' => $extractos]);
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo'        => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'banco'          => ['required', 'string', 'max:80'],
            'numero_cuenta'  => ['required', 'string', 'max:30'],
            'periodo_inicio' => ['required', 'date'],
            'periodo_fin'    => ['required', 'date', 'after_or_equal:periodo_inicio'],
            'saldo_inicial'  => ['sometimes', 'numeric'],
        ]);

        $resultado = $this->service->importarCsv(
            $request->file('archivo'),
            $request->only(['banco', 'numero_cuenta', 'periodo_inicio', 'periodo_fin', 'saldo_inicial']),
            (string) $request->user()?->id,
        );

        return response()->json(['success' => true, 'data' => $resultado], Response::HTTP_CREATED);
    }

    public function lineas(string $id): JsonResponse
    {
        $extracto = DB::table('extractos_bancarios')->where('id', $id)->first();
        if (! $extracto) {
            return response()->json(['success' => false, 'message' => 'Extracto no encontrado.'], 404);
        }

        $lineas = DB::table('lineas_extracto')
            ->where('extracto_id', $id)
            ->orderBy('fecha')
            ->get();

        $stats = [
            'total'       => $lineas->count(),
            'conciliadas' => $lineas->where('estado_conciliacion', 'conciliado')->count(),
            'pendientes'  => $lineas->where('estado_conciliacion', 'pendiente')->count(),
            'total_debito'  => $lineas->sum('debito'),
            'total_credito' => $lineas->sum('credito'),
        ];

        return response()->json(['success' => true, 'data' => $lineas, 'extracto' => $extracto, 'stats' => $stats]);
    }

    public function conciliarAuto(string $id): JsonResponse
    {
        $resultado = $this->service->conciliarAuto($id);

        return response()->json(['success' => true, 'data' => $resultado]);
    }

    public function conciliarManual(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'linea_id'   => ['required', 'string'],
            'origen_type'=> ['required', 'string', 'in:ReciboCaja,ComprobanteEgreso'],
            'origen_id'  => ['required', 'string'],
            'nota'       => ['nullable', 'string'],
        ]);

        try {
            $this->service->conciliarManual(
                $validated['linea_id'],
                $validated['origen_type'],
                $validated['origen_id'],
                $validated['nota'] ?? null,
                (string) $request->user()?->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json(['success' => true]);
    }

    /**
     * FEAT-H: Reporte de Conciliación Bancaria (papel de trabajo).
     *
     * GET /extractos-bancarios/{id}/reporte-conciliacion?cuenta_id=N
     *
     * Si se proporciona cuenta_id (cuenta del banco en libros), retorna también
     * la conciliación matemática completa con diferencia esperada = 0.
     */
    public function reporteConciliacion(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'cuenta_id' => ['nullable', 'uuid', 'exists:cuentas_contables,id'],
        ]);

        try {
            $reporte = $this->reporte->generar($id, $request->input('cuenta_id'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data'    => $reporte,
        ]);
    }
}
