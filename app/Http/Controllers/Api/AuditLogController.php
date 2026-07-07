<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $service) {}

    /**
     * Resuelve el tenant_id actual con política Fail-Closed.
     * Si el contexto de tenancy no está disponible, lanza 400 en lugar de
     * exponer registros de otros tenants (principio Zero-Trust).
     */
    private function resolvedTenantId(): string
    {
        $tenantId = function_exists('tenant') ? tenant('id') : null;
        if ($tenantId === null || $tenantId === '') {
            abort(Response::HTTP_BAD_REQUEST, 'Se requiere contexto tenant.');
        }

        return (string) $tenantId;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        // SEGURIDAD: Fail-Closed — nunca exponer registros sin contexto tenant confirmado.
        $tenantId = $this->resolvedTenantId();

        $query = AuditLog::query()->where('tenant_id', $tenantId);

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($crit = $request->query('criticidad')) {
            $query->where('criticidad', $crit);
        }
        if ($user = $request->query('user_id')) {
            $query->where('user_id', $user);
        }
        if ($type = $request->query('auditable_type')) {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $type);
            $query->where('auditable_type', 'like', "%{$safe}");
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 200));

        $page = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => AuditLogResource::collection($page->items()),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        // SEGURIDAD: filtrar por tenant ANTES del findOrFail para evitar oracle IDOR.
        $tenantId = $this->resolvedTenantId();

        $log = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->authorize('view', $log);

        return response()->json([
            'success' => true,
            'data'    => new AuditLogResource($log),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', AuditLog::class);

        // SEGURIDAD: Fail-Closed — sin tenant confirmado, no exportar nada.
        $tenantId = $this->resolvedTenantId();

        $callback = function () use ($tenantId): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id', 'tenant_id', 'created_at', 'action', 'criticidad',
                'user_id', 'user_email', 'user_role', 'auditable_type', 'auditable_id',
                'motivo', 'ip', 'user_agent', 'hash_actual',
            ]);

            $query = AuditLog::query()->where('tenant_id', $tenantId);

            foreach ($query->orderBy('created_at')->cursor() as $log) {
                fputcsv($out, [
                    $log->id, $log->tenant_id, (string) $log->created_at,
                    $log->action, $log->criticidad,
                    $log->user_id, $log->user_email_snapshot, $log->user_role_snapshot,
                    $log->auditable_type, $log->auditable_id,
                    $log->motivo, $log->ip_address, $log->user_agent, $log->hash_actual,
                ]);
            }
            fclose($out);
        };

        return response()->streamDownload(
            $callback,
            'audit-logs-'.now()->format('Y-m-d-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function verifyChain(Request $request): JsonResponse
    {
        $this->authorize('verifyChain', AuditLog::class);

        $tenantId = $this->resolvedTenantId();

        $invalidId = $this->service->verifyChainForTenant($tenantId);

        return response()->json([
            'success' => $invalidId === null,
            'data'    => [
                'tenant_id'        => $tenantId,
                'integrity_ok'     => $invalidId === null,
                'first_invalid_id' => $invalidId,
            ],
        ]);
    }
}
