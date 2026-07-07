<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Mail\CompanyCreatedWelcomeMail;
use App\Mail\NewTenantRegisteredAdminMail;
use App\Models\Tenant\ConceptoNomina;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Impuesto;
use App\Models\Tenant\ParametrizacionContable;
use App\Services\Registration\TenantRegistrationNotificationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regresión BUG-009: al crear un tenant vía POST /api/v1/tenants, deben
 * sembrarse TODOS los catálogos base, no solo el PUC.
 *
 * Antes del fix: el controller solo invocaba TenantPucSeeder directamente
 * con `(new TenantPucSeeder())->run()`. Esto dejaba `impuestos`,
 * `parametrizacion_contable` y `conceptos_nomina` en cero, dejando los
 * módulos de nómina e impuestos no-funcionales en cualquier tenant nuevo.
 *
 * Adicionalmente: la línea `$this->command->info(...)` al final de cada
 * seeder fallaba con "Call to a member function info() on null" cuando se
 * instanciaba el seeder directamente (sin contexto de Artisan Command),
 * causando un HTTP 500 falso aunque los inserts SÍ se hubieran ejecutado
 * (BUG-001).
 *
 * Fix: el controller ahora invoca los 4 seeders vía `Artisan::call('db:seed', ...)`,
 * lo que provee un Command real y permite que `$this->command` no sea null.
 *
 * Este test NO usa TenantTestCase porque ese case asume tenants ya
 * provisionados; aquí necesitamos golpear el endpoint público y validar la
 * provisión completa. El tearDown elimina la BD física y el registro central.
 */
class TenantStoreCatalogosSembradosTest extends TestCase
{
    private ?string $tenantId = null;

    protected function tearDown(): void
    {
        if ($this->tenantId !== null) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            DB::purge('tenant');

            $dbName = 'tenant' . $this->tenantId;
            try {
                DB::connection('pgsql')->statement("DROP DATABASE IF EXISTS \"{$dbName}\" WITH (FORCE)");
            } catch (\Throwable) {
                // Si el DROP falla, seguimos. El delete del registro central
                // dispara TenantDeleted → reintento de drop por stancl/tenancy.
            }

            $tenant = Tenant::withoutEvents(fn () => Tenant::find($this->tenantId));
            if ($tenant !== null) {
                Tenant::withoutEvents(fn () => $tenant->delete());
            }

            $this->tenantId = null;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_post_tenants_responde_201_y_no_500_falso(): void
    {
        $payload = $this->payloadValido('9001234567-1', 'admin.regresion.bug009@saas-contable.test');

        $this->limpiarTenantPrevio($payload['nit']);

        $response = $this->postJson('/api/v1/tenants', $payload);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['tenant_slug', 'razon_social', 'nit']]);

        // El response NO debe filtrar mensajes internos (defensa BUG-001).
        $this->assertArrayNotHasKey('error', $response->json());

        $tenant = Tenant::where('nit', $payload['nit'])->firstOrFail();
        $this->tenantId = (string) $tenant->id;
        $this->assertSame($tenant->tenant_slug, $response->json('data.tenant_slug'));
        $this->assertArrayNotHasKey('id', $response->json('data'));

    }

    public function test_registration_notification_service_encola_correos_sin_password_ni_uuid(): void
    {
        Mail::fake();
        Config::set('queue.default', 'database');
        Config::set('platform_admins.notification_recipients', [[
            'name' => 'SaaS Root',
            'email' => 'root.notifications@saas-contable.test',
        ]]);

        $tenant = new Tenant([
            'id' => '88000000-0000-0000-0000-000000000099',
            'tenant_slug' => 'empresa-mail-segura',
            'razon_social' => 'Empresa Mail Segura S.A.S.',
            'nit' => '9001230000-1',
            'email_contacto' => 'contacto.mail.segura@saas-contable.test',
            'plan_id' => 'trial',
            'activo' => true,
        ]);
        $tenant->created_at = now();
        $tenant->setAttribute('trial_ends_at', now()->addDays(14));

        $service = app(TenantRegistrationNotificationService::class);
        $mailData = $service->buildMailData($tenant, [
            'admin_name' => 'Admin Seguro',
            'admin_email' => 'admin.mail.segura@saas-contable.test',
        ]);
        $encoded = (string) json_encode($mailData);

        $this->assertSame('empresa-mail-segura', $mailData['tenant_slug']);
        $this->assertSame('admin.mail.segura@saas-contable.test', $mailData['admin_email']);
        $this->assertStringNotContainsString('ChangeMe123', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString((string) $tenant->id, $encoded);

        $service->send($tenant, [
            'admin_name' => 'Admin Seguro',
            'admin_email' => 'admin.mail.segura@saas-contable.test',
        ]);

        Mail::assertQueued(CompanyCreatedWelcomeMail::class);
        Mail::assertQueued(NewTenantRegisteredAdminMail::class);
    }

    public function test_tenant_nuevo_tiene_puc_impuestos_parametrizacion_y_conceptos_nomina(): void
    {
        $payload = $this->payloadValido('9001234567-2', 'admin.regresion.bug009.b@saas-contable.test');

        $this->limpiarTenantPrevio($payload['nit']);

        $response = $this->postJson('/api/v1/tenants', $payload);
        $response->assertCreated();
        $tenant = Tenant::where('nit', $payload['nit'])->firstOrFail();
        $this->tenantId = (string) $tenant->id;
        tenancy()->initialize($tenant);

        // PUC: el seeder siembra 251 cuentas según los datos actuales.
        // Asertamos un umbral conservador (>= 200) para no quebrar el test
        // ante futuras ampliaciones del PUC.
        $pucCount = CuentaContable::query()->count();
        $this->assertGreaterThanOrEqual(
            200,
            $pucCount,
            "Esperaba al menos 200 cuentas en PUC, hay {$pucCount}. ¿TenantPucSeeder no se ejecutó?",
        );

        $impuestosCount = Impuesto::query()->count();
        $this->assertGreaterThan(
            0,
            $impuestosCount,
            'BUG-009: impuestos vacíos. TenantImpuestosSeeder no se ejecutó al crear el tenant.',
        );

        $paramCount = ParametrizacionContable::query()->count();
        $this->assertGreaterThan(
            0,
            $paramCount,
            'BUG-009: parametrizacion_contable vacía. TenantParametrizacionSeeder no se ejecutó.',
        );

        $conceptosCount = ConceptoNomina::query()->count();
        $this->assertGreaterThan(
            0,
            $conceptosCount,
            'BUG-009: conceptos_nomina vacíos. TenantConceptosNominaSeeder no se ejecutó. '
            . 'Esto bloquea cualquier liquidación de nómina.',
        );
    }

    public function test_tenant_nuevo_tiene_impuestos_clave_para_operar(): void
    {
        $payload = $this->payloadValido('9001234567-3', 'admin.regresion.bug009.c@saas-contable.test');

        $this->limpiarTenantPrevio($payload['nit']);

        $response = $this->postJson('/api/v1/tenants', $payload);
        $response->assertCreated();
        $tenant = Tenant::where('nit', $payload['nit'])->firstOrFail();
        $this->tenantId = (string) $tenant->id;
        tenancy()->initialize($tenant);

        // Sin estos códigos, los flujos típicos (compras con retefuente,
        // venta con IVA) no pueden calcular. Validamos que el seeder los
        // siembra como mínimo viable.
        $codigosObligatorios = [
            'IVA-19',          // venta general
            'IVA-5',           // venta canasta familiar / arroz / etc.
            'RF-COMPRAS-25',   // retefuente compras declarante
        ];

        foreach ($codigosObligatorios as $codigo) {
            $existe = Impuesto::query()->where('codigo', $codigo)->where('activa', true)->exists();
            $this->assertTrue(
                $existe,
                "BUG-009: impuesto obligatorio '{$codigo}' no existe en el tenant nuevo. "
                . "Verifica TenantImpuestosSeeder.",
            );
        }
    }

    public function test_tenant_nuevo_tiene_conceptos_nomina_minimos(): void
    {
        $payload = $this->payloadValido('9001234567-4', 'admin.regresion.bug009.d@saas-contable.test');

        $this->limpiarTenantPrevio($payload['nit']);

        $response = $this->postJson('/api/v1/tenants', $payload);
        $response->assertCreated();
        $tenant = Tenant::where('nit', $payload['nit'])->firstOrFail();
        $this->tenantId = (string) $tenant->id;
        tenancy()->initialize($tenant);

        // Sin estos códigos LiquidacionNominaService::liquidar() no logra
        // mapear sus líneas y la liquidación queda sin desglose.
        $codigosObligatorios = ['BASICO', 'DED_SALUD', 'DED_PENSION'];

        foreach ($codigosObligatorios as $codigo) {
            $existe = ConceptoNomina::query()->where('codigo', $codigo)->exists();
            $this->assertTrue(
                $existe,
                "BUG-009: concepto obligatorio '{$codigo}' no existe. "
                . "LiquidacionNominaService::liquidar() lo busca explícitamente.",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function payloadValido(string $nit, string $adminEmail): array
    {
        return [
            'razon_social'   => 'Empresa Test BUG-009 ' . substr($nit, -1) . ' S.A.S.',
            'nit'            => $nit,
            'email_contacto' => "contacto-{$nit}@saas-contable.test",
            'telefono'       => '6011234567',
            'direccion'      => 'Calle 100 #15-20',
            'ciudad'         => 'Bogotá',
            'admin_nombre'   => 'Admin',
            'admin_apellido' => 'Regresion',
            'admin_email'    => $adminEmail,
            'admin_password' => 'password1234',
        ];
    }

    private function limpiarTenantPrevio(string $nit): void
    {
        $previo = Tenant::where('nit', $nit)->first();
        if ($previo !== null) {
            $previo->delete();
        }
    }
}
