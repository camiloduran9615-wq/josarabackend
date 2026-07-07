<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Database\Seeders\TenantSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Garantiza que existan 2 tenants de prueba (test-empresa-a y test-empresa-b)
 * con BDs, migrations y seeders listos antes de que el test los use.
 *
 * Idempotente: si los tenants ya existen, no hace nada.
 */
trait ProvisionTestTenants
{
    /** @var array<string, bool> */
    private static array $tenantsProvisioned = [];

    protected function ensureTestTenantsExist(): void
    {
        $cacheKey = static::class;
        if (isset(self::$tenantsProvisioned[$cacheKey])) {
            return;
        }

        $fixtures = [
            [
                // UUID fijo — facilita idempotencia y cumple con audit_logs.tenant_id UUID
                'id'             => '99000000-0000-0000-0000-000000000001',
                'razon_social'   => 'Empresa Test A S.A.S.',
                'nit'            => '9001112221',
                'email_contacto' => 'test-a@saas-contable.test',
            ],
            [
                'id'             => '99000000-0000-0000-0000-000000000002',
                'razon_social'   => 'Empresa Test B S.A.S.',
                'nit'            => '9003334442',
                'email_contacto' => 'test-b@saas-contable.test',
            ],
        ];

        foreach ($fixtures as $data) {
            $dbName = 'tenant' . $data['id'];

            // Check physical DB existence (independent of the central tenant record)
            $dbExists = \Illuminate\Support\Facades\DB::connection('pgsql')
                ->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$dbName]);

            $tenant = \App\Models\Tenant::find($data['id']);

            // Case: both record and DB exist — already provisioned. Still
            // verify the seed baseline below; previous interrupted runs can
            // leave a DB migrated but missing the default admin user.
            if ($tenant !== null && $dbExists !== null) {
                tenancy()->initialize($tenant);
                if (! \App\Models\User::query()->where('role', \App\Models\User::ROLE_ADMIN)->exists()) {
                    Artisan::call('db:seed', [
                        '--class' => TenantSeeder::class,
                        '--force' => true,
                    ]);
                }
                tenancy()->end();
                continue;
            }

            // Case: orphaned physical DB — central record was wiped (e.g. AuthTest RefreshDatabase)
            // Drop the stale DB so stancl/tenancy can recreate it cleanly via TenantCreated event.
            if ($tenant === null && $dbExists !== null) {
                \Illuminate\Support\Facades\DB::connection('pgsql')
                    ->statement("DROP DATABASE \"{$dbName}\" WITH (FORCE)");
                $dbExists = null;
            }

            if ($tenant === null) {
                // stancl/tenancy TenantCreated event creates the DB + runs migrations synchronously
                $tenant = \App\Models\Tenant::create(array_merge($data, [
                    'plan_id' => 'trial',
                    'activo'  => true,
                ]));
            } else {
                // Case: record exists but DB was dropped manually — recreate DB and re-migrate
                \Illuminate\Support\Facades\DB::connection('pgsql')
                    ->statement("CREATE DATABASE \"{$dbName}\"");
                Artisan::call('tenants:migrate', [
                    '--tenants' => [$tenant->id],
                    '--force'   => true,
                ]);
            }

            tenancy()->initialize($tenant);
            Artisan::call('db:seed', [
                '--class' => TenantSeeder::class,
                '--force' => true,
            ]);
            tenancy()->end();
        }

        self::$tenantsProvisioned[$cacheKey] = true;
    }
}
