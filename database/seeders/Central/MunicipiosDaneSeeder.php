<?php

declare(strict_types=1);

namespace Database\Seeders\Central;

use App\Models\Central\MunicipioDane;
use Illuminate\Database\Seeder;

/**
 * Catálogo DANE — 6 capitales principales para MVP.
 *
 * Códigos DANE oficiales (5 dígitos = depto 2 + municipio 3).
 * Se siembra solo lo mínimo para que las tarifas ICA del MVP tengan FK válida.
 * Carga masiva (~1.100 municipios) se hace después vía CSV import (no MVP).
 *
 * Idempotente: usa upsert sobre la PK natural `codigo_dane`.
 */
final class MunicipiosDaneSeeder extends Seeder
{
    public function run(): void
    {
        $municipios = [
            // codigo_dane, nombre, depto_dane, depto_nombre, region
            ['11001', 'Bogotá D.C.',  '11', 'Bogotá D.C.',  'Andina'],
            ['05001', 'Medellín',     '05', 'Antioquia',    'Andina'],
            ['76001', 'Cali',         '76', 'Valle del Cauca', 'Pacífico'],
            ['08001', 'Barranquilla', '08', 'Atlántico',    'Caribe'],
            ['68001', 'Bucaramanga',  '68', 'Santander',    'Andina'],
            ['13001', 'Cartagena',    '13', 'Bolívar',      'Caribe'],
        ];

        foreach ($municipios as [$codigo, $nombre, $deptoDane, $deptoNombre, $region]) {
            MunicipioDane::query()->updateOrCreate(
                ['codigo_dane' => $codigo],
                [
                    'municipio_nombre'    => $nombre,
                    'departamento_dane'   => $deptoDane,
                    'departamento_nombre' => $deptoNombre,
                    'region'              => $region,
                    'activo'              => true,
                ],
            );
        }

        $this->command?->info(sprintf('Sembrados %d municipios DANE.', count($municipios)));
    }
}
