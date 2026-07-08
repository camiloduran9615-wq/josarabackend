<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FactusIntegrationException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Resolucion;
use App\Services\FactusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResolucionController extends Controller
{
    protected $factusService;

    public function __construct(FactusService $factusService)
    {
        $this->factusService = $factusService;
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Resolucion::orderBy('fecha_fin', 'desc')->get()
        ]);
    }

    /**
     * Sincroniza las resoluciones desde Factus.
     */
    public function syncFromFactus()
    {
        try {
            $ranges = $this->factusService->getNumberingRanges();
        } catch (FactusIntegrationException $e) {
            Log::warning('factus.resoluciones_sync_failed', $this->factusLogContext([
                'error_type' => $e::class,
                'external_status' => $e->externalStatus(),
            ]));

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->clientStatus());
        } catch (Throwable $e) {
            Log::error('factus.resoluciones_sync_unexpected_error', $this->factusLogContext([
                'error_type' => $e::class,
            ]));

            return response()->json([
                'success' => false,
                'message' => 'No fue posible sincronizar las resoluciones DIAN. Intenta nuevamente.',
            ], 500);
        }
        
        if (!$ranges || !isset($ranges['data'])) {
            return response()->json(['success' => false, 'message' => 'No se pudieron obtener rangos de Factus'], 502);
        }

        $count = 0;
        foreach ($ranges['data'] as $range) {
            Resolucion::updateOrCreate(
                ['factus_id' => $range['id']],
                [
                    'nombre'            => ($range['document'] ?? $range['prefix'] ?? 'Sin Prefijo') . ($range['prefix'] ? ' (' . $range['prefix'] . ')' : ''),
                    'prefijo'           => $range['prefix'] ?? null,
                    'desde'             => $range['from'] ?? 0,
                    'hasta'             => $range['to'] ?? 999999999,
                    'numero_resolucion' => $range['number'] ?? 'N/A',
                    'fecha_inicio'      => $range['start_date'] ?? now()->toDateString(),
                    'fecha_fin'         => $range['expiration_date'] ?? now()->addYears(5)->toDateString(),
                    'activa'            => $range['is_active'] ?? true,
                ]
            );
            $count++;
        }

        return response()->json([
            'success' => true,
            'message' => "Se han sincronizado {$count} resoluciones correctamente."
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function factusLogContext(array $extra = []): array
    {
        return array_merge([
            'tenant_id' => tenant('id'),
            'tenant_slug' => tenant('tenant_slug') ?: tenant('company_code') ?: tenant('id'),
            'user_id' => auth()->id(),
            'endpoint' => 'resoluciones/sync',
        ], $extra);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string',
            'prefijo' => 'nullable|string',
            'desde' => 'required|integer',
            'hasta' => 'required|integer',
            'numero_resolucion' => 'required|string',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'factus_id' => 'nullable|integer',
        ]);

        $resolucion = Resolucion::create($validated);

        return response()->json([
            'success' => true,
            'data' => $resolucion
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $resolucion = Resolucion::findOrFail($id);

        // Resoluciones sincronizadas desde Factus/DIAN son inmutables en campos legales.
        // La DIAN es la fuente de verdad — solo permitimos activar/inactivar localmente.
        if ($resolucion->factus_id !== null) {
            $validated = $request->validate([
                'activa' => 'sometimes|boolean',
            ]);

            $rejected = array_diff(
                array_keys($request->except(['activa'])),
                ['_method']
            );
            if (!empty($rejected)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta resolución fue sincronizada desde la DIAN/Factus. Solo puedes activarla o inactivarla localmente; los campos legales no son editables.',
                    'errors'  => ['factus_id' => ['Resolución protegida (origen: DIAN)']],
                ], 422);
            }

            $resolucion->update($validated);
            return response()->json(['success' => true, 'data' => $resolucion]);
        }

        // Resoluciones creadas manualmente: editables como antes.
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string',
            'prefijo' => 'nullable|string',
            'desde' => 'sometimes|required|integer',
            'hasta' => 'sometimes|required|integer',
            'numero_resolucion' => 'sometimes|required|string',
            'fecha_inicio' => 'sometimes|required|date',
            'fecha_fin' => 'sometimes|required|date',
            'activa' => 'boolean'
        ]);

        $resolucion->update($validated);

        return response()->json([
            'success' => true,
            'data' => $resolucion
        ]);
    }

    public function destroy($id)
    {
        $resolucion = Resolucion::findOrFail($id);
        // Inactivamos en lugar de borrar físicamente para mantener historial
        $resolucion->update(['activa' => !$resolucion->activa]);

        return response()->json([
            'success' => true,
            'message' => $resolucion->activa ? 'Resolución activada' : 'Resolución inactivada'
        ]);
    }
}
