<?php

declare(strict_types=1);

namespace Tests\Feature\Plans;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use Tests\TenantTestCase;

class TenantPlanLimitTest extends TenantTestCase
{
    private string $planCode = 'qa-limit-test';

    protected function tearDown(): void
    {
        $central = config('tenancy.database.central_connection', config('database.default'));

        $planIds = Plan::on($central)
            ->where('code', $this->planCode)
            ->pluck('id');

        if ($planIds->isNotEmpty()) {
            Subscription::on($central)->whereIn('plan_id', $planIds)->delete();
            PlanFeature::on($central)->whereIn('plan_id', $planIds)->delete();
            Plan::on($central)->whereIn('id', $planIds)->forceDelete();
        }

        parent::tearDown();
    }

    public function test_bloquea_creacion_de_productos_cuando_el_plan_supera_el_limite(): void
    {
        $this->assignPlanLimit('max_products', 0);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/'.$this->tenant->publicIdentifier().'/productos', [
                'codigo' => 'QA-LIMIT-001',
                'nombre' => 'Producto QA Limitado',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.plan_limit.feature_key', 'max_products')
            ->assertJsonPath('errors.plan_limit.limit', 0);
    }

    public function test_bloquea_creacion_de_usuarios_cuando_el_plan_alcanza_el_limite(): void
    {
        $this->assignPlanLimit('max_users', 1);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/'.$this->tenant->publicIdentifier().'/users', [
                'nombre' => 'QA',
                'apellido' => 'Limit',
                'email' => 'qa.limit.user@example.test',
                'password' => 'NoDocumentar123!',
                'password_confirmation' => 'NoDocumentar123!',
                'role' => 'readonly',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.plan_limit.feature_key', 'max_users')
            ->assertJsonPath('errors.plan_limit.limit', 1);
    }

    private function assignPlanLimit(string $featureKey, int $limit): void
    {
        $central = config('tenancy.database.central_connection', config('database.default'));

        $plan = Plan::on($central)->create([
            'name' => 'QA Limit Test',
            'code' => $this->planCode,
            'description' => 'Plan temporal para pruebas de límites tenant',
            'monthly_price' => 0,
            'annual_price' => 0,
            'currency' => 'COP',
            'status' => Plan::STATUS_ACTIVE,
            'is_recommended' => false,
            'is_free' => true,
            'display_order' => 999,
            'trial_allowed' => true,
            'trial_days' => 14,
        ]);

        PlanFeature::on($central)->create([
            'plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'feature_label' => $featureKey,
            'limit_value' => $limit,
            'enabled' => true,
        ]);

        Subscription::on($central)->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->endOfMonth(),
            'price' => 0,
            'currency' => 'COP',
            'payment_status' => 'paid',
        ]);
    }
}
