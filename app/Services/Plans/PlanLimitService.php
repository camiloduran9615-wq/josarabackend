<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\PlanFeature;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlanLimitService
{
    private const CREATE_LIMITS = [
        'users' => ['feature' => 'max_users', 'table' => 'users', 'label' => 'usuarios'],
        'terceros' => ['feature' => 'max_third_parties', 'table' => 'terceros', 'label' => 'terceros'],
        'productos' => ['feature' => 'max_products', 'table' => 'productos', 'label' => 'productos'],
        'facturas' => ['feature' => 'max_invoices_month', 'table' => 'facturas', 'label' => 'facturas del mes', 'monthly' => true],
        'facturas-compra' => ['feature' => 'max_purchase_orders_month', 'table' => 'documentos_ingreso', 'label' => 'compras del mes', 'monthly' => true],
        'documentos-ingreso' => ['feature' => 'max_purchase_orders_month', 'table' => 'documentos_ingreso', 'label' => 'compras del mes', 'monthly' => true],
        'bodegas' => ['feature' => 'max_warehouses', 'table' => 'bodegas', 'label' => 'bodegas'],
        'centros-costo' => ['feature' => 'max_cost_centers', 'table' => 'centros_costo', 'label' => 'centros de costo'],
    ];

    /**
     * @return array{allowed: bool, feature_key?: string, limit?: int, used?: int, resource?: string}
     */
    public function evaluateCreate(Request $request): array
    {
        if ($request->method() !== 'POST') {
            return ['allowed' => true];
        }

        $resource = $this->firstResourceSegment($request);
        if ($resource === null || ! isset(self::CREATE_LIMITS[$resource])) {
            return ['allowed' => true];
        }

        $tenantId = tenant('id');
        if (! is_string($tenantId) || $tenantId === '') {
            return ['allowed' => true];
        }

        $definition = self::CREATE_LIMITS[$resource];
        $limit = $this->limitForTenant($tenantId, $definition['feature']);
        if ($limit === null || $limit < 0) {
            return ['allowed' => true];
        }

        $used = $this->currentUsage($definition['table'], (bool) ($definition['monthly'] ?? false));
        if ($used < $limit) {
            return ['allowed' => true];
        }

        return [
            'allowed' => false,
            'feature_key' => $definition['feature'],
            'limit' => $limit,
            'used' => $used,
            'resource' => $definition['label'],
        ];
    }

    private function firstResourceSegment(Request $request): ?string
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        $uri = ltrim($route->uri(), '/');
        $parts = explode('/', $uri);

        return $parts[3] ?? null;
    }

    private function limitForTenant(string $tenantId, string $featureKey): ?int
    {
        $central = config('tenancy.database.central_connection', config('database.default'));

        $subscription = Subscription::on($central)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('created_at')
            ->first();

        if ($subscription === null || $subscription->plan_id === null) {
            return null;
        }

        $feature = PlanFeature::on($central)
            ->where('plan_id', $subscription->plan_id)
            ->where('feature_key', $featureKey)
            ->first();

        if ($feature === null || ! $feature->enabled) {
            return null;
        }

        return $feature->limit_value;
    }

    private function currentUsage(string $table, bool $monthly): int
    {
        if (! Schema::connection('tenant')->hasTable($table)) {
            return 0;
        }

        $query = DB::connection('tenant')->table($table);

        if ($monthly && Schema::connection('tenant')->hasColumn($table, 'created_at')) {
            $query->where('created_at', '>=', now()->startOfMonth());
        }

        return (int) $query->count();
    }
}
