<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asiento\ReverseAsientoRequest;
use App\Http\Requests\Asiento\StoreAsientoRequest;
use App\Http\Requests\Asiento\UpdateAsientoRequest;
use App\Http\Requests\Asiento\VoidAsientoRequest;
use App\Http\Resources\AsientoResource;
use App\Models\Tenant\Asiento;
use App\Services\Asiento\AsientoOperacionInvalidaException;
use App\Services\Asiento\AsientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AsientoController extends Controller
{
    public function __construct(private readonly AsientoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asiento::class);

        $query = Asiento::query()->with(['lineas', 'periodo']);

        // Filtros
        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }
        if ($desde = $request->query('fecha_desde')) {
            $query->whereDate('fecha', '>=', $desde);
        }
        if ($hasta = $request->query('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $hasta);
        }
        if ($tipo = $request->query('tipo_comprobante')) {
            $query->where('tipo_comprobante', $tipo);
        }
        if ($periodoId = $request->query('periodo_id')) {
            $query->where('periodo_id', $periodoId);
        }
        if ($origenType = $request->query('origen_type')) {
            // Escapar wildcards de LIKE para evitar table-scan y information disclosure.
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $origenType);
            $query->where('origen_type', 'like', "%{$safe}");
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 200));

        $sort = (string) $request->query('sort', '-fecha');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        $allowedSorts = ['fecha', 'numero', 'estado', 'created_at'];
        if (in_array($field, $allowedSorts, true)) {
            $query->orderBy($field, $direction);
        } else {
            $query->orderByDesc('fecha')->orderByDesc('created_at');
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => AsientoResource::collection($page->items()),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function store(StoreAsientoRequest $request): JsonResponse
    {
        $user = $request->user();

        $asiento = $this->service->crearBorrador(
            data: $request->validated(),
            lineas: (array) $request->validated('lineas'),
            autor: $user,
        );

        return response()->json([
            'success' => true,
            'data'    => new AsientoResource($asiento->loadMissing(['lineas'])),
        ], Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $asiento = Asiento::query()->with(['lineas', 'periodo'])->findOrFail($id);
        $this->authorize('view', $asiento);

        return response()->json([
            'success' => true,
            'data'    => new AsientoResource($asiento),
        ]);
    }

    public function update(UpdateAsientoRequest $request, string $id): JsonResponse
    {
        $asiento = Asiento::query()->findOrFail($id);
        $this->authorize('update', $asiento); // defensa en profundidad (FormRequest ya validó)

        $asiento = $this->service->editarBorrador(
            asiento: $asiento,
            data: $request->validated(),
            lineas: $request->has('lineas') ? (array) $request->validated('lineas') : null,
            editor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'data'    => new AsientoResource($asiento->loadMissing(['lineas'])),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $asiento = Asiento::query()->findOrFail($id);
        $this->authorize('discard', $asiento);

        $this->service->descartar($asiento);

        return response()->json([
            'success' => true,
            'message' => 'Borrador descartado.',
        ]);
    }

    // -----------------------------------------------------------------------
    // Acciones de dominio
    // -----------------------------------------------------------------------

    public function aprobar(Request $request, string $id): JsonResponse
    {
        $asiento = Asiento::query()->with('lineas', 'periodo')->findOrFail($id);
        $this->authorize('approve', $asiento);

        try {
            $aprobado = $this->service->aprobar($asiento, $request->user());
        } catch (AsientoOperacionInvalidaException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data'    => new AsientoResource($aprobado->loadMissing(['lineas'])),
        ]);
    }

    public function anular(VoidAsientoRequest $request, string $id): JsonResponse
    {
        $asiento = Asiento::query()->with('periodo')->findOrFail($id);
        $this->authorize('void', $asiento); // defensa en profundidad (FormRequest ya validó)

        try {
            $anulado = $this->service->anular(
                $asiento,
                $request->user(),
                (string) $request->validated('motivo'),
            );
        } catch (AsientoOperacionInvalidaException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data'    => new AsientoResource($anulado->loadMissing(['lineas'])),
        ]);
    }

    public function reversar(ReverseAsientoRequest $request, string $id): JsonResponse
    {
        $asiento = Asiento::query()->with('lineas')->findOrFail($id);
        $this->authorize('reverse', $asiento); // defensa en profundidad (FormRequest ya validó)

        try {
            $reverso = $this->service->reversar(
                $asiento,
                $request->user(),
                (string) $request->validated('motivo'),
                (string) $request->validated('fecha_reverso'),
            );
        } catch (AsientoOperacionInvalidaException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data'    => new AsientoResource($reverso->loadMissing(['lineas'])),
        ]);
    }

    // -----------------------------------------------------------------------

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], Response::HTTP_CONFLICT);
    }
}
