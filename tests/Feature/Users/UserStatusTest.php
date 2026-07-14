<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use App\Models\User;
use Tests\TenantTestCase;

class UserStatusTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializeTenancyByTenantIdentifier::class);
    }

    private function tenantUrl(User $user): string
    {
        return sprintf('/api/v1/%s/users/%s/status', $this->tenant->id, $user->id);
    }

    private function adminToken(): string
    {
        return $this->adminUser->createToken('user-status-test', ['*'])->plainTextToken;
    }

    public function test_admin_can_inactivate_and_reactivate_another_user(): void
    {
        $target = User::factory()->create();
        $target->createToken('target-session', ['read']);
        $token = $this->adminToken();

        $this->withToken($token)
            ->patchJson($this->tenantUrl($target), ['activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false);

        $this->assertFalse((bool) $target->fresh()->activo);
        $this->assertSame(0, $target->tokens()->count());

        $this->withToken($token)
            ->patchJson($this->tenantUrl($target), ['activo' => true])
            ->assertOk()
            ->assertJsonPath('data.activo', true);

        $this->assertTrue((bool) $target->fresh()->activo);
    }

    public function test_admin_cannot_inactivate_own_account(): void
    {
        $this->withToken($this->adminToken())
            ->patchJson($this->tenantUrl($this->adminUser), ['activo' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activo');

        $this->assertTrue((bool) $this->adminUser->fresh()->activo);
    }

    public function test_non_admin_cannot_change_user_status(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_CONTADOR]);
        $target = User::factory()->create();
        $token = $actor->createToken('non-admin-status-test', ['read', 'update'])->plainTextToken;

        $this->withToken($token)
            ->patchJson($this->tenantUrl($target), ['activo' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->activo);
    }

    public function test_status_payload_requires_a_boolean(): void
    {
        $target = User::factory()->create();

        $this->withToken($this->adminToken())
            ->patchJson($this->tenantUrl($target), ['activo' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activo');
    }
}
