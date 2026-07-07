<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminAuditLog;
use App\Models\PlatformOperationEvent;
use App\Models\PlatformSetting;
use App\Models\PlatformStatusCheck;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\PlatformAdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class OperationsController extends Controller
{
    public function __construct(private readonly PlatformAdminAuditService $audit)
    {
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'business' => $this->businessMetrics(),
                'operations' => $this->operationsMetrics(),
                'security' => $this->securityMetrics(),
                'support' => $this->supportMetrics(),
                'health' => $this->healthSummary(),
                'recent_events' => PlatformOperationEvent::query()
                    ->latest()
                    ->limit(8)
                    ->get(),
            ],
        ]);
    }

    public function observability(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'health' => $this->healthSummary(),
                'status_checks' => PlatformStatusCheck::query()
                    ->latest('checked_at')
                    ->limit(20)
                    ->get(),
                'queue' => $this->queueMetrics(),
                'database' => $this->databaseMetrics(),
                'events' => PlatformOperationEvent::query()
                    ->whereIn('category', ['system', 'availability', 'performance', 'integration'])
                    ->latest()
                    ->limit(20)
                    ->get(),
            ],
        ]);
    }

    public function security(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $this->securityMetrics(),
                'admins' => PlatformAdmin::query()
                    ->select(['id', 'name', 'email', 'role', 'active', 'last_login_at', 'created_at'])
                    ->orderBy('name')
                    ->get(),
                'recent_audit_logs' => PlatformAdminAuditLog::with('platformAdmin')
                    ->latest('created_at')
                    ->limit(25)
                    ->get(),
                'security_events' => PlatformOperationEvent::query()
                    ->where('category', 'security')
                    ->latest()
                    ->limit(20)
                    ->get(),
            ],
        ]);
    }

    public function support(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $this->supportMetrics(),
                'tickets' => SupportTicket::with(['tenant', 'assignedTo'])
                    ->latest('last_activity_at')
                    ->latest()
                    ->limit(50)
                    ->get(),
                'at_risk_tenants' => Tenant::query()
                    ->whereIn('status', [Tenant::STATUS_SUSPENDED, Tenant::STATUS_PAYMENT_PENDING, Tenant::STATUS_EXPIRED])
                    ->orderByDesc('updated_at')
                    ->limit(20)
                    ->get(['id', 'tenant_slug', 'company_code', 'razon_social', 'nit', 'status', 'billing_status', 'email_contacto', 'updated_at']),
            ],
        ]);
    }

    public function settings(): JsonResponse
    {
        $settings = PlatformSetting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(function (PlatformSetting $setting): array {
                return [
                    'id' => $setting->id,
                    'key' => $setting->key,
                    'group' => $setting->group,
                    'type' => $setting->type,
                    'value' => $setting->is_sensitive ? null : $setting->value,
                    'is_sensitive' => $setting->is_sensitive,
                    'description' => $setting->description,
                    'updated_at' => $setting->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'runtime' => [
                    'platform_name' => config('platform.name'),
                    'app_env' => config('app.env'),
                    'debug' => (bool) config('app.debug'),
                    'timezone' => config('app.timezone'),
                    'queue_connection' => config('queue.default'),
                    'cache_store' => config('cache.default'),
                ],
            ],
        ]);
    }

    public function upsertSetting(Request $request): JsonResponse
    {
        /** @var mixed $user */
        $user = $request->user();

        if (! $user instanceof PlatformAdmin || ! $user->canManagePlatform()) {
            abort(Response::HTTP_FORBIDDEN, 'No autorizado.');
        }

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_.-]+$/'],
            'group' => ['required', 'string', 'max:80'],
            'type' => ['required', 'string', 'max:40'],
            'value' => ['nullable'],
            'description' => ['nullable', 'string'],
            'is_sensitive' => ['sometimes', 'boolean'],
        ]);

        $setting = PlatformSetting::updateOrCreate(
            ['key' => $validated['key']],
            [
                'group' => $validated['group'],
                'type' => $validated['type'],
                'value' => ['value' => $validated['value'] ?? null],
                'description' => $validated['description'] ?? null,
                'is_sensitive' => $validated['is_sensitive'] ?? false,
                'updated_by_platform_admin_id' => $user->id,
            ],
        );

        $this->audit->log($request, 'platform_setting.upserted', PlatformSetting::class, (string) $setting->id, [
            'key' => $setting->key,
            'group' => $setting->group,
            'is_sensitive' => $setting->is_sensitive,
        ]);

        return response()->json(['success' => true, 'data' => $setting]);
    }

    /**
     * @return array<string, int|float>
     */
    private function businessMetrics(): array
    {
        $subscriptions = Subscription::whereIn('status', ['active', 'trialing'])->get();
        $mrr = $subscriptions->sum(fn (Subscription $subscription): float => $subscription->billing_cycle === 'annual'
            ? (float) $subscription->price / 12
            : (float) $subscription->price);

        return [
            'tenants_total' => Tenant::count(),
            'tenants_active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
            'tenants_trial' => Tenant::where('status', Tenant::STATUS_TRIAL)->count(),
            'tenants_suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
            'subscriptions_active' => Subscription::where('status', 'active')->count(),
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function operationsMetrics(): array
    {
        return [
            'open_events' => PlatformOperationEvent::whereNull('resolved_at')->count(),
            'critical_events' => PlatformOperationEvent::whereNull('resolved_at')
                ->where('severity', PlatformOperationEvent::SEVERITY_CRITICAL)
                ->count(),
            'status_checks' => PlatformStatusCheck::count(),
            'settings' => PlatformSetting::count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function supportMetrics(): array
    {
        return [
            'tickets_open' => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
            'tickets_high_priority' => SupportTicket::whereIn('priority', ['high', 'urgent'])
                ->whereNotIn('status', ['closed', 'resolved'])
                ->count(),
            'tenants_at_risk' => Tenant::whereIn('status', [
                Tenant::STATUS_SUSPENDED,
                Tenant::STATUS_EXPIRED,
                Tenant::STATUS_PAYMENT_PENDING,
            ])->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function securityMetrics(): array
    {
        return [
            'platform_admins_total' => PlatformAdmin::count(),
            'platform_admins_active' => PlatformAdmin::where('active', true)->count(),
            'failed_admin_logins_24h' => PlatformAdminAuditLog::where('action', 'platform_admin.login.failed')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'critical_security_events' => PlatformOperationEvent::where('category', 'security')
                ->where('severity', PlatformOperationEvent::SEVERITY_CRITICAL)
                ->whereNull('resolved_at')
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthSummary(): array
    {
        return [
            'api' => ['status' => 'operational', 'checked_at' => now()],
            'database' => $this->databaseHealth(),
            'cache' => $this->cacheHealth(),
            'queue' => $this->queueMetrics(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseHealth(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return [
                'status' => 'operational',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable) {
            return ['status' => 'degraded', 'latency_ms' => null];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheHealth(): array
    {
        $key = 'occ_health_'.bin2hex(random_bytes(4));

        try {
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['status' => $ok ? 'operational' : 'degraded'];
        } catch (\Throwable) {
            return ['status' => 'degraded'];
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function queueMetrics(): array
    {
        if (! Schema::hasTable('jobs')) {
            return ['status' => 'unavailable', 'pending' => 0, 'failed' => 0];
        }

        return [
            'status' => 'operational',
            'pending' => DB::table('jobs')->count(),
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function databaseMetrics(): array
    {
        return [
            'central_tenants' => Tenant::count(),
            'audit_logs' => Schema::hasTable('audit_logs') ? DB::table('audit_logs')->count() : 0,
            'platform_admin_audit_logs' => PlatformAdminAuditLog::count(),
            'driver' => config('database.default'),
        ];
    }
}
