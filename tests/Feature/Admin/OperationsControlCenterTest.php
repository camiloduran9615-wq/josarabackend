<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\PlatformAdmin;
use App\Models\PlatformOperationEvent;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OperationsControlCenterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tenant_user_cannot_access_operations_center(): void
    {
        $user = User::create([
            'nombre' => 'Tenant',
            'apellido' => 'Admin',
            'email' => 'tenant-occ@example.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'activo' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/operations/overview')
            ->assertStatus(403);
    }

    public function test_platform_admin_can_read_operations_overview(): void
    {
        PlatformOperationEvent::create([
            'category' => 'system',
            'severity' => PlatformOperationEvent::SEVERITY_WARNING,
            'title' => 'Queue latency elevated',
            'source' => 'scheduler',
        ]);

        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/operations/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['business', 'operations', 'security', 'support', 'health', 'recent_events'],
            ]);
    }

    public function test_readonly_admin_cannot_update_platform_settings(): void
    {
        $this->actingAs($this->platformAdmin(PlatformAdmin::ROLE_READONLY_ADMIN), 'sanctum')
            ->putJson('/api/v1/admin/operations/settings', [
                'key' => 'billing.grace_days',
                'group' => 'billing',
                'type' => 'integer',
                'value' => 5,
            ])
            ->assertStatus(403);
    }

    public function test_super_admin_can_update_platform_settings_and_audit_is_recorded(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/operations/settings', [
                'key' => 'billing.grace_days',
                'group' => 'billing',
                'type' => 'integer',
                'value' => 5,
                'description' => 'Periodo de gracia de pago.',
            ])
            ->assertOk()
            ->assertJsonPath('data.key', 'billing.grace_days');

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'billing.grace_days',
            'group' => 'billing',
        ]);
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'action' => 'platform_setting.upserted',
        ]);
        $this->assertSame(['value' => 5], PlatformSetting::where('key', 'billing.grace_days')->first()?->value);
    }

    private function platformAdmin(string $role = PlatformAdmin::ROLE_SUPER_ADMIN): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Operations Owner',
            'email' => uniqid('occ-', true).'@example.test',
            'password' => 'SuperSecret123!',
            'role' => $role,
            'active' => true,
        ]);
    }
}
