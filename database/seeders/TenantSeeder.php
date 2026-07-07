<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder de tenant: debe ejecutarse CON tenancy activa.
 *
 * Uso:
 *   php artisan tenants:seed --class=Database\\Seeders\\TenantSeeder
 *   php artisan tenants:seed --tenants=<uuid> --class=Database\\Seeders\\TenantSeeder
 *
 * Orden:
 *   1. TenantPucSeeder              — cuentas contables PUC
 *   2. TenantImpuestosSeeder        — catálogo IVA/ReteFuente/ReteIVA (depende del PUC)
 *   3. TenantParametrizacionSeeder  — claves canónicas módulo→cuenta
 *   4. TenantAdminSeeder            — usuario administrador inicial
 */
final class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantPucSeeder::class,
            TenantImpuestosSeeder::class,
            TenantParametrizacionSeeder::class,
            TenantConceptosNominaSeeder::class,
            TenantAdminSeeder::class,
        ]);
    }
}
