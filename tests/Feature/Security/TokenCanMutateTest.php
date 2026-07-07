<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use Tests\TenantTestCase;

/**
 * FIX REG-1 (ACCEPTANCE_REPORT.md) — el rol `auditor` (abilities ['read','export'])
 * NO debe poder mutar recursos de negocio. La ability 'export' solo habilita las
 * dos operaciones POST de auditoría (export / verify-chain), nunca como comodín
 * global de escritura.
 *
 * También cubre la no-regresión de FIX C-2 (readonly bloqueado) y de los roles de
 * escritura (auxiliar / admin siguen pudiendo mutar).
 *
 * Se usan tokens REALES con abilities (createToken) en vez de Sanctum::actingAs,
 * porque solo el token persistido evalúa las abilities con match exacto —
 * exactamente el mecanismo que el middleware EnsureTokenCanMutate consulta.
 */
class TokenCanMutateTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Igual que AuthTest: evita que cada request HTTP reinicialice el tenant
        // y rompa la transacción de rollback de TenantTestCase.
        $this->withoutMiddleware(InitializeTenancyByTenantIdentifier::class);
    }

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function tokenFor(string $role, array $abilities): string
    {
        /** @var User $user */
        $user = User::factory()->create(['role' => $role]);

        return $user->createToken('test-'.$role, $abilities)->plainTextToken;
    }

    // ── REG-1: auditor NO puede mutar negocio ────────────────────────────────

    public function test_auditor_cannot_create_business_resource(): void
    {
        $token = $this->tokenFor(User::ROLE_AUDITOR, ['read', 'export']);

        $this->withToken($token)
            ->postJson($this->tenantUrl('/terceros'), [
                'tipo_documento' => 'NIT',
                'numero_documento' => '900123456',
                'razon_social' => 'Auditor Bypass Test',
            ])
            ->assertStatus(403);
    }

    public function test_auditor_cannot_delete_business_resource(): void
    {
        $token = $this->tokenFor(User::ROLE_AUDITOR, ['read', 'export']);

        // Aunque el id no exista, el middleware corta ANTES del controlador:
        // debe responder 403 (no 404), probando que la mutación nunca llega.
        $this->withToken($token)
            ->deleteJson($this->tenantUrl('/terceros/00000000-0000-0000-0000-000000000000'))
            ->assertStatus(403);
    }

    public function test_auditor_cannot_update_via_put(): void
    {
        $token = $this->tokenFor(User::ROLE_AUDITOR, ['read', 'export']);

        $this->withToken($token)
            ->putJson($this->tenantUrl('/productos/00000000-0000-0000-0000-000000000000'), [
                'nombre' => 'x',
            ])
            ->assertStatus(403);
    }

    // ── REG-1: auditor SÍ conserva su función legítima (export de auditoría) ──

    public function test_auditor_can_still_hit_audit_export_route(): void
    {
        $token = $this->tokenFor(User::ROLE_AUDITOR, ['read', 'export']);

        $response = $this->withToken($token)
            ->postJson($this->tenantUrl('/audit-logs/export'), [
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => '2026-12-31',
            ]);

        // El middleware NO debe bloquear esta ruta para 'export'. Cualquier
        // respuesta distinta de 403 prueba que la ability route-scoped funciona
        // (200 stream CSV, 422 validación, etc. — pero jamás 403 del middleware).
        // getStatusCode() se reenvía al baseResponse (soporta StreamedResponse).
        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'auditor con ability export debe poder ejecutar POST /audit-logs/export'
        );
    }

    // ── No-regresión: readonly sigue bloqueado (FIX C-2) ─────────────────────

    public function test_readonly_cannot_create_business_resource(): void
    {
        $token = $this->tokenFor(User::ROLE_READONLY, ['read']);

        $this->withToken($token)
            ->postJson($this->tenantUrl('/terceros'), [
                'tipo_documento' => 'NIT',
                'numero_documento' => '900123457',
                'razon_social' => 'Readonly Test',
            ])
            ->assertStatus(403);
    }

    // ── No-regresión: roles de escritura no se ven afectados ─────────────────

    public function test_auxiliar_passes_the_mutation_gate(): void
    {
        $token = $this->tokenFor(User::ROLE_AUXILIAR, ['read', 'create', 'update']);

        $response = $this->withToken($token)
            ->postJson($this->tenantUrl('/terceros'), []);

        // No debe ser bloqueado por el middleware (403). Puede ser 422 por
        // validación del FormRequest — eso prueba que atravesó la puerta.
        $this->assertNotSame(403, $response->status());
    }

    public function test_admin_wildcard_passes_the_mutation_gate(): void
    {
        $token = $this->tokenFor(User::ROLE_ADMIN, ['*']);

        $response = $this->withToken($token)
            ->postJson($this->tenantUrl('/terceros'), []);

        $this->assertNotSame(403, $response->status());
    }
}
