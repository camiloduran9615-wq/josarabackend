<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Tenant\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantSlugAuthTest extends TestCase
{
    private ?Tenant $tenant = null;

    protected function tearDown(): void
    {
        try {
            tenancy()->end();

            if ($this->tenant !== null) {
                $this->tenant->refresh();
                $this->tenant->delete();
            }
        } catch (\Throwable) {
            // Limpieza best-effort para no dejar tenants huérfanos en pruebas.
        }

        parent::tearDown();
    }

    public function test_login_accepts_tenant_slug_and_hides_internal_uuid(): void
    {
        [$tenant, $password, $email] = $this->createTenantWithAdmin('Empresa Login S.A.S.', 'admin@login.example');

        $response = $this->postJson('/api/v1/auth/login', [
            'tenant_slug' => $tenant->tenant_slug,
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenant_slug', $tenant->tenant_slug)
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.company_code');
    }

    public function test_login_still_accepts_legacy_tenant_uuid_temporarily(): void
    {
        [$tenant, $password, $email] = $this->createTenantWithAdmin('Empresa Legacy S.A.S.', 'admin@legacy.example');

        $response = $this->postJson('/api/v1/auth/login', [
            'tenant_id' => $tenant->id,
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.tenant_slug', $tenant->tenant_slug)
            ->assertJsonMissingPath('data.tenant_id');
    }

    public function test_register_returns_slug_and_does_not_expose_uuid(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $nit = sprintf('900123%03d-%d', random_int(0, 999), random_int(0, 9));

        $response = $this->postJson('/api/v1/tenants', [
            'razon_social' => 'Empresa Registro '.$suffix,
            'tenant_slug' => 'empresa-registro-'.$suffix,
            'nit' => $nit,
            'email_contacto' => 'contacto-'.$suffix.'@example.test',
            'admin_nombre' => 'Admin',
            'admin_apellido' => 'Registro',
            'admin_email' => 'admin-'.$suffix.'@example.test',
            'admin_password' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenant_slug', 'empresa-registro-'.$suffix)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.company_code');

        $this->tenant = Tenant::query()->where('nit', $nit)->first();
        $this->assertNotNull($this->tenant);
        $this->assertSame('empresa-registro-'.$suffix, $this->tenant?->tenant_slug);
    }

    /**
     * @return array{0: Tenant, 1: string, 2: string}
     */
    private function createTenantWithAdmin(string $companyName, string $email): array
    {
        $suffix = bin2hex(random_bytes(3));
        $password = 'Secret123!';

        $tenant = Tenant::create([
            'razon_social' => $companyName.' '.$suffix,
            'nit' => sprintf('800123%03d-%d', random_int(0, 999), random_int(0, 9)),
            'email_contacto' => 'tenant-'.$suffix.'@example.test',
            'tenant_slug' => 'tenant-'.$suffix,
            'company_code' => 'tenant-'.$suffix,
            'plan_id' => 'trial',
            'activo' => true,
        ]);

        tenancy()->initialize($tenant);

        $sucursal = Sucursal::firstOrCreate([
            'es_principal' => true,
        ], [
            'nombre' => 'Casa Matriz',
            'activa' => true,
        ]);

        User::create([
            'nombre' => 'Admin',
            'apellido' => 'Tenant',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_ADMIN,
            'sucursal_id' => $sucursal->id,
            'activo' => true,
        ]);

        tenancy()->end();

        $this->tenant = $tenant;

        return [$tenant, $password, $email];
    }
}
