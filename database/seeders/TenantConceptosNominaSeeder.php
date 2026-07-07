<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant\ConceptoNomina;
use Illuminate\Database\Seeder;

class TenantConceptosNominaSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    private const CONCEPTOS = [
        // ── Devengados ─────────────────────────────────────────────────────
        ['BASICO',          'Salario Básico',                'devengado', 'basico',           true,  true,  false],
        ['AUX_TRANSPORTE',  'Auxilio de Transporte',         'devengado', 'auxilio',           false, false, false],
        ['H_EXTRA_DIURNA',  'Hora Extra Ordinaria Diurna',   'devengado', 'hora_extra',        true,  true,  false],
        ['H_EXTRA_NOCT',    'Hora Extra Ordinaria Nocturna', 'devengado', 'hora_extra',        true,  true,  false],
        ['H_EXTRA_FEST',    'Hora Extra Festiva Diurna',     'devengado', 'hora_extra',        true,  true,  false],
        ['RECARGO_NOCT',    'Recargo Nocturno',              'devengado', 'recargo',           true,  true,  false],
        ['COMISION',        'Comisión',                      'devengado', 'comision',          true,  true,  false],
        ['BONIF',           'Bonificación',                  'devengado', 'bonificacion',      false, false, false],
        ['VIATICOS',        'Viáticos',                      'devengado', 'viatico',           false, false, false],
        // Prestaciones sociales
        ['PRIMA',           'Prima de Servicios',            'devengado', 'prima',             false, false, true],
        ['CESANTIAS',       'Cesantías',                     'devengado', 'cesantia',          false, false, true],
        ['INT_CESANTIAS',   'Intereses a las Cesantías',     'devengado', 'cesantia',          false, false, true],
        ['VACACIONES',      'Vacaciones',                    'devengado', 'vacacion',          false, false, true],

        // ── Deducciones ────────────────────────────────────────────────────
        ['DED_SALUD',       'Aporte Salud Empleado 4%',      'deduccion', 'salud',             false, false, false],
        ['DED_PENSION',     'Aporte Pensión Empleado 4%',    'deduccion', 'pension',           false, false, false],
        ['DED_RETEFUENTE',  'Retención en la Fuente',        'deduccion', 'retefuente',        false, false, false],
        ['DED_EMBARGO',     'Embargo Judicial',              'deduccion', 'embargo',           false, false, false],
        ['DED_LIBRANZA',    'Libranza',                      'deduccion', 'libranza',          false, false, false],
        ['DED_SINDICATO',   'Cuota Sindical',                'deduccion', 'sindicato',         false, false, false],

        // ── Aportes Empleador (Ley 100, Ley 1607) ──────────────────────────
        // Estos NO se descuentan al empleado: son costo del empleador.
        // Se persisten en liquidacion_lineas con tipo='aporte_empleador'
        // para alimentar el asiento contable y el XML UBL DIAN.
        // SALUD, SENA, ICBF son exonerables (Ley 1607/2012) cuando el salario
        // individual del trabajador es ≤ 10 SMMLV y el empleador es persona
        // jurídica → la lógica de exoneración vive en LiquidacionNominaService.
        ['EMP_SALUD',       'Aporte Salud Empleador 8.5%',   'aporte_empleador', 'salud_emp',     true,  false, false],
        ['EMP_PENSION',     'Aporte Pensión Empleador 12%',  'aporte_empleador', 'pension_emp',   true,  false, false],
        ['EMP_ARL',         'ARL Empleador',                 'aporte_empleador', 'arl',           true,  false, false],
        ['EMP_CCF',         'Caja de Compensación 4%',       'aporte_empleador', 'ccf',           false, false, false],
        ['EMP_SENA',        'Aporte SENA 2%',                'aporte_empleador', 'sena',          false, false, false],
        ['EMP_ICBF',        'Aporte ICBF 3%',                'aporte_empleador', 'icbf',          false, false, false],
    ];

    public function run(): void
    {
        foreach (self::CONCEPTOS as [$codigo, $nombre, $tipo, $subtipo, $apSegSoc, $apRetef, $esPrest]) {
            ConceptoNomina::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'                    => $nombre,
                    'tipo'                      => $tipo,
                    'subtipo'                   => $subtipo,
                    'aplica_seguridad_social'   => $apSegSoc,
                    'aplica_retefuente'         => $apRetef,
                    'es_prestacion_social'      => $esPrest,
                    'sistema'                   => true,
                    'activo'                    => true,
                ],
            );
        }
    }
}
