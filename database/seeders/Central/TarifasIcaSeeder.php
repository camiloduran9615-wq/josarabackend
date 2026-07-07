<?php

declare(strict_types=1);

namespace Database\Seeders\Central;

use App\Models\Central\TarifaIca;
use Illuminate\Database\Seeder;

/**
 * Tarifas ICA vigentes 2026 para las 6 capitales del MVP.
 *
 * Las tarifas son la consolidación del documento §3.4 del Contador
 * (fundamentos_libro_mayor_balances.md). Los códigos CIIU son los 4 dígitos
 * estándar DANE — los Acuerdos municipales agrupan por estos códigos.
 *
 * Vigencia: '2024-01-01' sin fecha de cierre (rige hasta próxima reforma municipal).
 * Idempotente: upsert por (municipio_dane, codigo_actividad_ciiu, vigencia_desde).
 *
 * Fuentes:
 *   - Bogotá:        Acuerdo 65/2002 modificado por Acuerdo 780/2020
 *   - Medellín:      Acuerdo 066/2017
 *   - Cali:          Acuerdo 0357/2013
 *   - Barranquilla:  Estatuto Tributario Distrital (Decreto 0180/2010)
 *   - Bucaramanga:   Acuerdo 044/2008
 *   - Cartagena:     Estatuto Tributario Distrital
 *
 * NOTA: las tarifas exactas pueden variar dentro de rangos por subcategoría.
 * Se siembra la tarifa promedio/representativa por categoría. Cada tenant puede
 * ajustar valores específicos vía endpoint admin (super-admin) — fuera de MVP.
 */
final class TarifasIcaSeeder extends Seeder
{
    public function run(): void
    {
        $vigenciaDesde = '2024-01-01';
        $tarifas       = [
            // ─── Bogotá D.C. (Acuerdo 65/2002 actualizado 780/2020) ──────
            ['11001', 'Bogotá D.C.',  '11', '1011', 'Procesamiento alimentos',         4.1400, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '4711', 'Comercio al por menor',           4.1400, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '4661', 'Comercio al por mayor',           4.1400, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '6201', 'Desarrollo de software',          9.6600, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '6920', 'Contabilidad / auditoría',        9.6600, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '7020', 'Consultoría de gestión',          9.6600, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '8610', 'Servicios de salud humana',       6.9000, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '6419', 'Servicios financieros generales', 11.0400, 'Acuerdo Bogotá 65/2002 art. 14'],
            ['11001', 'Bogotá D.C.',  '11', '6110', 'Telecomunicaciones',              9.6600, 'Acuerdo Bogotá 65/2002 art. 14'],

            // ─── Medellín (Acuerdo 066/2017) ──────────────────────────────
            ['05001', 'Medellín', '05', '1011', 'Industria alimentos',     7.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '4711', 'Comercio al por menor',   5.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '4661', 'Comercio al por mayor',   5.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '6201', 'Desarrollo de software',  10.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '6920', 'Contabilidad / auditoría', 10.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '7020', 'Consultoría de gestión',   10.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '6419', 'Servicios financieros',    5.0000, 'Acuerdo Medellín 066/2017'],
            ['05001', 'Medellín', '05', '4111', 'Construcción de edificios', 7.0000, 'Acuerdo Medellín 066/2017'],

            // ─── Cali (Acuerdo 0357/2013) ─────────────────────────────────
            ['76001', 'Cali', '76', '1011', 'Industria alimentos',    6.6000, 'Acuerdo Cali 0357/2013'],
            ['76001', 'Cali', '76', '4711', 'Comercio al por menor',  6.0000, 'Acuerdo Cali 0357/2013'],
            ['76001', 'Cali', '76', '4661', 'Comercio al por mayor',  4.4000, 'Acuerdo Cali 0357/2013'],
            ['76001', 'Cali', '76', '6201', 'Desarrollo de software', 7.0000, 'Acuerdo Cali 0357/2013'],
            ['76001', 'Cali', '76', '6920', 'Contabilidad / auditoría', 7.0000, 'Acuerdo Cali 0357/2013'],
            ['76001', 'Cali', '76', '7020', 'Consultoría de gestión',  7.0000, 'Acuerdo Cali 0357/2013'],

            // ─── Barranquilla (Decreto 0180/2010) ─────────────────────────
            ['08001', 'Barranquilla', '08', '1011', 'Industria alimentos',     5.5000, 'Decreto Barranquilla 0180/2010'],
            ['08001', 'Barranquilla', '08', '4711', 'Comercio al por menor',   7.0000, 'Decreto Barranquilla 0180/2010'],
            ['08001', 'Barranquilla', '08', '4661', 'Comercio al por mayor',   5.0000, 'Decreto Barranquilla 0180/2010'],
            ['08001', 'Barranquilla', '08', '6201', 'Desarrollo de software',  8.0000, 'Decreto Barranquilla 0180/2010'],
            ['08001', 'Barranquilla', '08', '6920', 'Contabilidad / auditoría', 8.0000, 'Decreto Barranquilla 0180/2010'],
            ['08001', 'Barranquilla', '08', '6419', 'Servicios financieros',   5.0000, 'Decreto Barranquilla 0180/2010'],

            // ─── Bucaramanga (Acuerdo 044/2008) ───────────────────────────
            ['68001', 'Bucaramanga', '68', '4711', 'Comercio al por menor',   6.0000, 'Acuerdo Bucaramanga 044/2008'],
            ['68001', 'Bucaramanga', '68', '4661', 'Comercio al por mayor',   6.0000, 'Acuerdo Bucaramanga 044/2008'],
            ['68001', 'Bucaramanga', '68', '6201', 'Desarrollo de software',  10.0000, 'Acuerdo Bucaramanga 044/2008'],
            ['68001', 'Bucaramanga', '68', '6920', 'Contabilidad / auditoría', 10.0000, 'Acuerdo Bucaramanga 044/2008'],
            ['68001', 'Bucaramanga', '68', '7020', 'Consultoría de gestión',  10.0000, 'Acuerdo Bucaramanga 044/2008'],

            // ─── Cartagena (Estatuto Tributario Distrital) ────────────────
            ['13001', 'Cartagena', '13', '4711', 'Comercio al por menor',   7.0000, 'Estatuto Tributario Cartagena'],
            ['13001', 'Cartagena', '13', '4661', 'Comercio al por mayor',   5.0000, 'Estatuto Tributario Cartagena'],
            ['13001', 'Cartagena', '13', '5510', 'Hotelería',               10.0000, 'Estatuto Tributario Cartagena'],
            ['13001', 'Cartagena', '13', '7911', 'Agencias de viaje',       10.0000, 'Estatuto Tributario Cartagena'],
            ['13001', 'Cartagena', '13', '6201', 'Desarrollo de software',  8.0000, 'Estatuto Tributario Cartagena'],
        ];

        $insertados = 0;
        foreach ($tarifas as $row) {
            [$municipio, $munNombre, $depto, $ciiu, $desc, $tarifa, $fuente] = $row;

            TarifaIca::query()->updateOrCreate(
                [
                    'municipio_dane'        => $municipio,
                    'codigo_actividad_ciiu' => $ciiu,
                    'vigencia_desde'        => $vigenciaDesde,
                ],
                [
                    'municipio_nombre'      => $munNombre,
                    'departamento_dane'     => $depto,
                    'descripcion_actividad' => $desc,
                    'tarifa_por_mil'        => $tarifa,
                    'base_minima_uvt'       => null,
                    'base_minima_cop'       => null,
                    'vigencia_hasta'        => null,
                    'activa'                => true,
                    'fuente_legal'          => $fuente,
                ],
            );
            ++$insertados;
        }

        $this->command?->info("Sembradas {$insertados} tarifas ICA.");
    }
}
