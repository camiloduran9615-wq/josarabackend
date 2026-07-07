<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Central\MunicipiosDaneSeeder;
use Database\Seeders\Central\TarifasIcaSeeder;
use Illuminate\Database\Seeder;

/**
 * Seeder principal — datos de la BD CENTRAL.
 *
 * Para datos de tenants usa `TenantSeeder` (se invoca con tenancy activa):
 *   php artisan tenants:seed --class=Database\\Seeders\\TenantSeeder
 *
 * Los datos UVT ya se siembran en la propia migración
 * (2026_06_01_000006_create_uvt_anual_table.php) para garantizar
 * que existen antes de que cualquier código de servicio los consulte.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogos compartidos de la BD central
        $this->call([
            MunicipiosDaneSeeder::class,
            TarifasIcaSeeder::class,
        ]);
    }
}
