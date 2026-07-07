<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\PlatformAdmin;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAdminAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_normal_tenant_user_cannot_access_platform_admin_routes(): void
    {
        $user = User::create([
            'nombre' => 'Tenant',
            'apellido' => 'Admin',
            'email' => 'tenant-admin@example.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'activo' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_super_admin_can_access_dashboard(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['tenants_total', 'mrr', 'arr']]);
    }

    public function test_platform_admin_login_returns_token(): void
    {
        PlatformAdmin::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'SuperSecret123!',
            'role' => PlatformAdmin::ROLE_SUPER_ADMIN,
            'active' => true,
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'SuperSecret123!',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'admin' => ['id', 'role']]]);
    }

    public function test_readonly_admin_cannot_create_plan(): void
    {
        $admin = $this->platformAdmin(PlatformAdmin::ROLE_READONLY_ADMIN);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/plans', $this->planPayload())
            ->assertStatus(403);
    }

    public function test_super_admin_can_create_plan_and_audit_is_recorded(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/plans', $this->planPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'pro');

        $this->assertDatabaseHas('plans', ['code' => 'pro']);
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'action' => 'plan.created',
        ]);
    }

    public function test_super_admin_can_change_tenant_plan_without_provisioning_tenant_database(): void
    {
        $admin = $this->platformAdmin();
        $plan = Plan::create($this->planPayload());
        $tenant = $this->tenant();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->id}/change-plan", [
                'plan_id' => $plan->id,
                'reason' => 'Actualización comercial',
                'effective_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJsonPath('data.plan_id', $plan->id);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'plan_id' => 'pro',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('subscription_history', [
            'tenant_id' => $tenant->id,
            'new_plan_id' => $plan->id,
            'reason' => 'Actualización comercial',
        ]);
    }

    private function platformAdmin(string $role = PlatformAdmin::ROLE_SUPER_ADMIN): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner-', true).'@example.test',
            'password' => 'SuperSecret123!',
            'role' => $role,
            'active' => true,
        ]);
    }

    private function tenant(): Tenant
    {
        $id = (string) Str::uuid();
        $suffix = random_int(100000, 999999);

        DB::table('tenants')->insert([
            'id' => $id,
            'company_code' => "empresa-admin-test-{$suffix}",
            'razon_social' => 'Empresa Admin Test S.A.S.',
            'nit' => "900{$suffix}-1",
            'email_contacto' => 'contacto@example.test',
            'plan_id' => 'trial',
            'activo' => true,
            'status' => Tenant::STATUS_TRIAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Tenant::findOrFail($id);
    }

    private function planPayload(): array
    {
        return [
            'name' => 'Pro',
            'code' => 'pro',
            'description' => 'Plan profesional',
            'monthly_price' => 199000,
            'annual_price' => 1990000,
            'currency' => 'COP',
            'status' => Plan::STATUS_ACTIVE,
            'is_recommended' => true,
            'is_free' => false,
            'display_order' => 1,
            'trial_allowed' => true,
            'trial_days' => 14,
            'features' => [
                ['feature_key' => 'max_users', 'feature_label' => 'Usuarios', 'limit_value' => 10, 'enabled' => true],
                ['feature_key' => 'dian_integration', 'feature_label' => 'Integración DIAN', 'enabled' => true],
            ],
        ];
    }
}
