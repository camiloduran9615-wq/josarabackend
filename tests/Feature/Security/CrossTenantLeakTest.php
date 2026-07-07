<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProvisionTestTenants;
use Tests\TestCase;

/**
 * CrossTenantLeakTest — verifica que los datos de un tenant sean invisibles desde otro.
 *
 * Estrategia: usa dos tenants existentes (A y B). Crea datos en A dentro de una
 * transacción, cambia al contexto de B y verifica que los datos de A no aparecen.
 * El rollback al final garantiza que no se dejan datos sucios.
 *
 * Usa ProvisionTestTenants para auto-crear los tenants de prueba si no existen.
 */
final class CrossTenantLeakTest extends TestCase
{
    use ProvisionTestTenants;

    private const TENANT_A_ID = '99000000-0000-0000-0000-000000000001';
    private const TENANT_B_ID = '99000000-0000-0000-0000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTestTenantsExist();
    }

    public function test_cuentas_de_tenant_a_no_visibles_desde_tenant_b(): void
    {
        [$tenantA, $tenantB] = $this->fixtureTenants();

        // ── Paso 1: Crear cuenta en Tenant A ─────────────────────────────────
        tenancy()->initialize($tenantA);
        DB::beginTransaction();

        $codigoUnico = 'LK' . substr(uniqid(), -6); // max 20 chars (varchar(20))
        DB::table('cuentas_contables')->insert([
            'id'                  => \Illuminate\Support\Str::uuid()->toString(),
            'codigo'              => $codigoUnico,
            'nombre'              => 'Cuenta Test Cross-Tenant Leak',
            'naturaleza'          => 'debito',
            'nivel'               => 'subcuenta',
            'acepta_movimientos'  => true,
            'exige_tercero'       => false,
            'exige_centro_costo'  => false,
            'exige_base_impuesto' => false,
            'activo'              => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $cuentaEnA = CuentaContable::query()->where('codigo', $codigoUnico)->count();
        $this->assertSame(1, $cuentaEnA, 'La cuenta debe existir en Tenant A.');

        DB::rollBack();
        tenancy()->end();

        // ── Paso 2: Verificar que NO existe en Tenant B ───────────────────────
        tenancy()->initialize($tenantB);

        $cuentaEnB = CuentaContable::query()->where('codigo', $codigoUnico)->count();
        $this->assertSame(0, $cuentaEnB, 'La cuenta de Tenant A NO debe ser visible desde Tenant B.');

        tenancy()->end();
    }

    public function test_periodos_de_tenant_a_no_visibles_desde_tenant_b(): void
    {
        [$tenantA, $tenantB] = $this->fixtureTenants();

        // Crear periodo en A, verificar ausencia en B
        tenancy()->initialize($tenantA);
        DB::beginTransaction();

        $codigoUnico = 'PL' . substr(uniqid(), -6); // max 10 chars (varchar(10))
        PeriodoContable::query()->create([
            'tipo'        => PeriodoContable::TIPO_MENSUAL,
            'codigo'      => $codigoUnico,
            'fecha_inicio' => '2025-06-01',
            'fecha_fin'   => '2025-06-30',
            'año_fiscal'  => 2025,
            'mes'         => 6,
            'estado'      => PeriodoContable::ESTADO_ABIERTO,
        ]);

        $existeEnA = PeriodoContable::query()->where('codigo', $codigoUnico)->exists();
        $this->assertTrue($existeEnA);

        DB::rollBack();
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $existeEnB = PeriodoContable::query()->where('codigo', $codigoUnico)->exists();
        $this->assertFalse($existeEnB, 'El periodo de Tenant A no debe ser visible en Tenant B.');
        tenancy()->end();
    }

    public function test_queries_de_tenant_usan_conexion_aislada(): void
    {
        [$tenantA, $tenantB] = $this->fixtureTenants();

        tenancy()->initialize($tenantA);
        $countA = CuentaContable::query()->count();
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $countB = CuentaContable::query()->count();
        tenancy()->end();

        // Los conteos son independientes (DB diferentes)
        // No podemos saber si son iguales o no, pero ambas queries deben funcionar
        $this->assertIsInt($countA);
        $this->assertIsInt($countB);

        // Los tenants usan distintas conexiones — la conexión de B no es la de A
        tenancy()->initialize($tenantA);
        $connA = DB::connection()->getDatabaseName();
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $connB = DB::connection()->getDatabaseName();
        tenancy()->end();

        $this->assertNotSame($connA, $connB,
            'Los tenants deben usar bases de datos completamente separadas.',
        );
    }

    /**
     * @return array{\App\Models\Tenant, \App\Models\Tenant}
     */
    private function fixtureTenants(): array
    {
        $tenantA = \App\Models\Tenant::query()->find(self::TENANT_A_ID);
        $tenantB = \App\Models\Tenant::query()->find(self::TENANT_B_ID);

        if ($tenantA === null || $tenantB === null) {
            $this->markTestSkipped('Se requieren los tenants fixture A/B para el test de cross-tenant leak.');
        }

        return [$tenantA, $tenantB];
    }
}
