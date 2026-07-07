<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantCompanyCodeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_normalizes_company_code_for_login_lookup(): void
    {
        $this->assertSame('empresa-demo', Tenant::normalizeCompanyCode(' Empresa Demo '));
        $this->assertSame('empresa-demo', Tenant::normalizeCompanyCode('Empresa Demo!'));
        $this->assertSame('empresa-demo', Tenant::normalizeCompanyCode('EMPRESA DÉMO'));
        $this->assertSame('empresa-demo', Tenant::normalizeTenantSlug(' Empresa Demo '));
    }

    public function test_generates_unique_company_code_without_touching_tenant_database(): void
    {
        DB::table('tenants')->insert([
            'id' => '88000000-0000-0000-0000-000000000001',
            'tenant_slug' => 'empresa-demo',
            'company_code' => 'empresa-demo',
            'razon_social' => 'Empresa Demo S.A.S.',
            'nit' => '800111222-1',
            'email_contacto' => 'demo@example.test',
            'plan_id' => 'trial',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('empresa-demo-2', Tenant::generateUniqueCompanyCode('Empresa Demo'));
        $this->assertSame('empresa-demo-2', Tenant::generateUniqueTenantSlug('Empresa Demo'));
    }

    public function test_resolves_tenant_by_slug_company_code_or_uuid(): void
    {
        DB::table('tenants')->insert([
            'id' => '88000000-0000-0000-0000-000000000002',
            'tenant_slug' => 'empresa-acme',
            'company_code' => 'empresa-acme',
            'razon_social' => 'Empresa ACME S.A.S.',
            'nit' => '800111222-2',
            'email_contacto' => 'acme@example.test',
            'plan_id' => 'trial',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull(Tenant::resolveByPublicIdentifier('empresa-acme'));
        $this->assertNotNull(Tenant::resolveByPublicIdentifier('Empresa ACME'));
        $this->assertNotNull(Tenant::resolveByPublicIdentifier('88000000-0000-0000-0000-000000000002'));
    }
}
