<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use Tests\TenantTestCase;

/**
 * Tests de autenticación y permisos.
 *
 * Extiende TenantTestCase para disponer de un tenant activo y $this->adminUser.
 * Se bypasea InitializeTenancyByTenantIdentifier en setUp para que las peticiones HTTP de
 * prueba no reinicialicen el tenant (lo que rompería la transacción de rollback).
 * La tenencia queda activa desde el setUp de TenantTestCase durante todo el test.
 */
class AuthTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Previene que cada petición HTTP reinicialice el tenant y destruya
        // la transacción activa de TenantTestCase.
        $this->withoutMiddleware(InitializeTenancyByTenantIdentifier::class);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    // ── Tests de Login HTTP ───────────────────────────────────────────────────
    // El endpoint POST /api/v1/auth/login llama a tenancy()->initialize($tenant)
    // internamente, lo que cierra la conexión y rompe el rollback de TenantTestCase.
    // Se marcan como incompletos hasta tener un harness de integración HTTP completo.

    public function test_user_can_login_with_valid_credentials(): void
    {
        $this->markTestIncomplete(
            'Pendiente: harness HTTP login multi-tenant — el endpoint requiere tenant_id en body y autentica contra la DB del tenant, lo que es incompatible con el rollback transaccional de TenantTestCase.',
        );
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->markTestIncomplete('Pendiente: harness HTTP login multi-tenant.');
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $this->markTestIncomplete('Pendiente: harness HTTP login multi-tenant.');
    }

    // ── Tests de /me ─────────────────────────────────────────────────────────

    public function test_me_returns_authenticated_user(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->tenantUrl('/auth/me'));

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.email', $this->adminUser->email);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson($this->tenantUrl('/auth/me'))->assertStatus(401);
    }

    // ── Tests de Logout ───────────────────────────────────────────────────────

    public function test_user_can_logout(): void
    {
        $newToken = $this->adminUser->createToken('test-logout');
        $tokenId  = $newToken->accessToken->id;

        $this->withToken($newToken->plainTextToken)
            ->postJson($this->tenantUrl('/auth/logout'))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verificar que el token fue efectivamente eliminado de la BD.
        // No se hace una segunda petición HTTP porque el guard de auth cachea
        // el usuario autenticado entre requests en el mismo test (comportamiento
        // conocido del test kernel de Laravel).
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    // ── Tests de Aislamiento ──────────────────────────────────────────────────

    public function test_unauthenticated_request_cannot_access_protected_routes(): void
    {
        $this->getJson($this->tenantUrl('/auth/me'))->assertStatus(401);
        $this->getJson($this->tenantUrl('/users'))->assertStatus(401);
    }

    // ── Tests de Roles y Permisos ─────────────────────────────────────────────

    public function test_user_resource_includes_permissions(): void
    {
        /** @var User $contador */
        $contador = User::factory()->create(['role' => User::ROLE_CONTADOR]);

        $response = $this->actingAs($contador, 'sanctum')
            ->getJson($this->tenantUrl('/auth/me'));

        $response->assertStatus(200)
            ->assertJsonPath('data.can.approve', true)
            ->assertJsonPath('data.can.void', true)
            ->assertJsonPath('data.can.manage_users', false);
    }

    public function test_auxiliar_cannot_approve_per_permissions(): void
    {
        /** @var User $auxiliar */
        $auxiliar = User::factory()->create(['role' => User::ROLE_AUXILIAR]);

        $response = $this->actingAs($auxiliar, 'sanctum')
            ->getJson($this->tenantUrl('/auth/me'));

        $response->assertStatus(200)
            ->assertJsonPath('data.can.approve', false)
            ->assertJsonPath('data.can.void', false);
    }
}
