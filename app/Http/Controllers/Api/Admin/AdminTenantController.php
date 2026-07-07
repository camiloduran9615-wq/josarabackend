<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\TenantStatusHistory;
use App\Services\PlatformAdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminTenantController extends Controller
{
    public function __construct(private readonly PlatformAdminAuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->with(['subscriptions.plan']);
        $search = $request->query('search');
        $status = $request->query('status');

        if (is_string($search) && $search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('razon_social', 'like', "%{$search}%")
                    ->orWhere('nit', 'like', "%{$search}%")
                    ->orWhere('tenant_slug', 'like', "%{$search}%")
                    ->orWhere('company_code', 'like', "%{$search}%")
                    ->orWhere('email_contacto', 'like', "%{$search}%");
            });
        }

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $tenants = $query->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 15));

        $tenants->getCollection()->transform(fn (Tenant $tenant) => $this->serializeTenantSummary($tenant));

        return response()->json(['success' => true, 'data' => $tenants]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load(['subscriptions.plan', 'usageSnapshots' => fn ($query) => $query->latest('snapshot_at')->limit(1)]);

        return response()->json([
            'success' => true,
            'data' => [
                'tenant' => $this->serializeTenantDetail($tenant),
                'usage' => $this->calculateUsage($tenant),
                'subscription_history' => SubscriptionHistory::with(['changedBy', 'previousPlan', 'newPlan'])
                    ->where('tenant_id', $tenant->id)
                    ->latest()
                    ->limit(20)
                    ->get(),
                'status_history' => TenantStatusHistory::with('changedBy')
                    ->where('tenant_id', $tenant->id)
                    ->latest()
                    ->limit(20)
                    ->get(),
            ],
        ]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeMutation($request);

        $validated = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'email_contacto' => ['required', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:80'],
            'billing_status' => ['nullable', 'string', 'max:40'],
            'electronic_invoicing_status' => ['nullable', 'string', 'max:40'],
        ]);

        $tenant->update($validated);

        $this->audit->log($request, 'tenant.updated', Tenant::class, (string) $tenant->id);

        $tenant->refresh();

        return response()->json(['success' => true, 'data' => $this->serializeTenantDetail($tenant)]);
    }

    public function changePlan(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeMutation($request);

        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'reason' => ['required', 'string', 'max:255'],
            'observation' => ['nullable', 'string'],
            'effective_mode' => ['required', Rule::in(['immediate', 'next_cycle'])],
        ]);

        /** @var Plan $plan */
        $plan = Plan::findOrFail($validated['plan_id']);
        $previousPlanCode = $tenant->plan_id;

        $subscription = DB::transaction(function () use ($request, $tenant, $plan, $validated, $previousPlanCode): Subscription {
            $subscription = Subscription::where('tenant_id', $tenant->id)
                ->whereNull('canceled_at')
                ->latest()
                ->first();

            $previousPlanId = $subscription?->plan_id;

            if ($subscription === null) {
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => now(),
                    'current_period_starts_at' => now(),
                    'current_period_ends_at' => now()->addMonth(),
                    'price' => $plan->monthly_price,
                    'currency' => $plan->currency,
                ]);
            } else {
                $subscription->update([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'price' => $subscription->billing_cycle === 'annual' ? $plan->annual_price : $plan->monthly_price,
                    'currency' => $plan->currency,
                ]);
            }

            $tenant->update([
                'plan_id' => $plan->code,
                'status' => Tenant::STATUS_ACTIVE,
                'activo' => true,
            ]);

            SubscriptionHistory::create([
                'subscription_id' => $subscription->id,
                'tenant_id' => $tenant->id,
                'previous_plan_id' => $previousPlanId,
                'new_plan_id' => $plan->id,
                'changed_by_platform_admin_id' => $request->user()?->id,
                'reason' => $validated['reason'],
                'observation' => $validated['observation'] ?? null,
                'effective_mode' => $validated['effective_mode'],
                'effective_at' => $validated['effective_mode'] === 'immediate' ? now() : $subscription->current_period_ends_at,
                'overuse_snapshot' => [
                    'previous_legacy_plan_id' => $previousPlanCode,
                ],
            ]);

            return $subscription->load('plan');
        });

        $this->audit->log($request, 'tenant.plan_changed', Tenant::class, (string) $tenant->id, [
            'plan_code' => $plan->code,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['success' => true, 'data' => $subscription]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->setStatus($request, $tenant, Tenant::STATUS_SUSPENDED, 'tenant.suspended');
    }

    public function reactivate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->setStatus($request, $tenant, Tenant::STATUS_ACTIVE, 'tenant.reactivated', true);
    }

    public function usage(Tenant $tenant): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->calculateUsage($tenant)]);
    }

    public function users(Tenant $tenant): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->readTenantUsers($tenant)]);
    }

    public function billing(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => Subscription::with('plan')
                    ->where('tenant_id', $tenant->id)
                    ->latest()
                    ->first(),
                'payment_status' => $tenant->billing_status,
                'next_charge_at' => Subscription::where('tenant_id', $tenant->id)
                    ->latest()
                    ->value('current_period_ends_at'),
                'invoices' => [],
                'payments' => [],
            ],
        ]);
    }

    private function setStatus(Request $request, Tenant $tenant, string $status, string $action, bool $activate = false): JsonResponse
    {
        $this->authorizeMutation($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($tenant->status === Tenant::STATUS_CANCELLED && $status !== Tenant::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede modificar una empresa cancelada con esta acción.',
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(function () use ($request, $tenant, $status, $activate, $validated): void {
            $previous = $tenant->status;

            $tenant->update([
                'status' => $status,
                'activo' => $activate || $status === Tenant::STATUS_ACTIVE,
            ]);

            TenantStatusHistory::create([
                'tenant_id' => $tenant->id,
                'previous_status' => $previous,
                'new_status' => $status,
                'changed_by_platform_admin_id' => $request->user()?->id,
                'reason' => $validated['reason'],
                'metadata' => $validated['metadata'] ?? null,
            ]);
        });

        $this->audit->log($request, $action, Tenant::class, (string) $tenant->id, ['reason' => $validated['reason']]);

        $tenant->refresh();

        return response()->json(['success' => true, 'data' => $this->serializeTenantDetail($tenant)]);
    }

    private function serializeTenantSummary(Tenant $tenant): array
    {
        /** @var Subscription|null $subscription */
        $subscription = $tenant->subscriptions->sortByDesc('created_at')->first();
        $planActual = $tenant->plan_id;
        $planCode = $tenant->plan_id;

        if ($subscription instanceof Subscription && $subscription->plan_id !== null) {
            $planActual = (string) ($subscription->plan()->value('name') ?: $tenant->plan_id);
            $planCode = (string) ($subscription->plan()->value('code') ?: $tenant->plan_id);
        }

        return [
            'id' => $tenant->id,
            'tenant_slug' => $tenant->publicIdentifier(),
            'company_code' => $tenant->company_code,
            'razon_social' => $tenant->razon_social,
            'nit' => $tenant->nit,
            'status' => $tenant->status ?? ($tenant->activo ? Tenant::STATUS_ACTIVE : Tenant::STATUS_SUSPENDED),
            'activo' => $tenant->activo,
            'plan_actual' => $planActual,
            'plan_code' => $planCode,
            'created_at' => $tenant->created_at,
            'last_access_at' => $tenant->last_access_at,
            'users_count' => null,
            'documents_count' => null,
            'storage_bytes_used' => $tenant->storage_bytes_used ?? 0,
            'payment_status' => $tenant->billing_status,
            'electronic_invoicing_status' => $tenant->electronic_invoicing_status,
            'ciudad' => $tenant->ciudad,
            'country' => $tenant->country,
            'contact_name' => null,
            'email_contacto' => $tenant->email_contacto,
            'telefono' => $tenant->telefono,
        ];
    }

    private function serializeTenantDetail(Tenant $tenant): array
    {
        return array_merge($this->serializeTenantSummary($tenant->loadMissing('subscriptions.plan')), [
            'direccion' => $tenant->direccion,
            'trial_ends_at' => $tenant->trial_ends_at,
            'days_active' => $tenant->created_at->diffInDays(now()),
            'subscriptions' => $tenant->subscriptions,
        ]);
    }

    private function calculateUsage(Tenant $tenant): array
    {
        $usage = [
            'users' => 0,
            'cost_centers' => 0,
            'warehouses' => 0,
            'requisitions_month' => 0,
            'quotes_month' => 0,
            'purchase_orders_month' => 0,
            'invoices_month' => 0,
            'products' => 0,
            'third_parties' => 0,
            'storage_bytes' => (int) ($tenant->storage_bytes_used ?? 0),
            'api_requests_month' => 0,
        ];

        try {
            tenancy()->initialize($tenant);

            $usage['users'] = $this->countTenantTable('users');
            $usage['cost_centers'] = $this->countTenantTable('centros_costo');
            $usage['warehouses'] = $this->countTenantTable('bodegas');
            $usage['quotes_month'] = $this->countTenantTable('cotizaciones', true);
            $usage['purchase_orders_month'] = $this->countTenantTable('documentos_ingreso', true);
            $usage['invoices_month'] = $this->countTenantTable('facturas', true);
            $usage['products'] = $this->countTenantTable('productos');
            $usage['third_parties'] = $this->countTenantTable('terceros');
        } catch (Throwable) {
            $usage['unavailable'] = true;
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }

        return $usage;
    }

    private function countTenantTable(string $table, bool $currentMonth = false): int
    {
        if (! Schema::connection('tenant')->hasTable($table)) {
            return 0;
        }

        $query = DB::connection('tenant')->table($table);

        if ($currentMonth && Schema::connection('tenant')->hasColumn($table, 'created_at')) {
            $query->where('created_at', '>=', now()->startOfMonth());
        }

        return (int) $query->count();
    }

    private function readTenantUsers(Tenant $tenant): array
    {
        try {
            tenancy()->initialize($tenant);

            if (! Schema::connection('tenant')->hasTable('users')) {
                return [];
            }

            return DB::connection('tenant')
                ->table('users')
                ->select(['id', 'nombre', 'apellido', 'email', 'role', 'activo', 'last_login', 'created_at'])
                ->orderBy('nombre')
                ->get()
                ->map(fn ($user) => (array) $user)
                ->all();
        } catch (Throwable) {
            return [];
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    private function authorizeMutation(Request $request): void
    {
        /** @var mixed $user */
        $user = $request->user();

        if (! $user instanceof \App\Models\PlatformAdmin || ! $user->canManagePlatform()) {
            abort(Response::HTTP_FORBIDDEN, 'No autorizado.');
        }
    }
}
