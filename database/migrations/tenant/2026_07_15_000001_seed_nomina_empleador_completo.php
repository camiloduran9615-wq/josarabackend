<?php

declare(strict_types=1);

use App\Models\Tenant\ConceptoNomina;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use Illuminate\Database\Migrations\Migration;

/**
 * Aportes empleador (Ley 100/1993 + Ley 1607/2012):
 *
 *  1) Agrega cuentas de GASTO de personal al PUC (5105xx) que faltaban —
 *     hasta ahora las cuentas de provisiones (cesantías, prima, vacaciones)
 *     y aportes empleador (salud, pensión, ARL, parafiscales) solo existían
 *     como PASIVOS (237xxx, 251xxx, 252xxx) sin la contrapartida débito.
 *
 *  2) Parametriza las claves canónicas `nomina.cuenta_gasto_*` apuntando
 *     a esas cuentas — el AsientoNominaService las usa para construir el
 *     asiento contable al aprobar la liquidación (DÉBITO).
 *
 *  3) Siembra los CONCEPTOS de aporte empleador (EMP_SALUD, EMP_PENSION,
 *     EMP_ARL, EMP_CCF, EMP_SENA, EMP_ICBF) — el LiquidacionNominaService
 *     persiste líneas con `tipo='aporte_empleador'` referenciando estos
 *     conceptos.
 *
 * Idempotente: no falla si las cuentas/claves/conceptos ya existen.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string,3:string}> */
    private array $cuentasPuc = [
        // [codigo, nombre, naturaleza, nivel] — todas subcuentas dentro de 5105
        ['510515', 'Horas Extras y Recargos',                 'debito', 'subcuenta'],
        ['510530', 'Cesantías',                               'debito', 'subcuenta'],
        ['510533', 'Intereses sobre Cesantías',               'debito', 'subcuenta'],
        ['510536', 'Prima de Servicios',                      'debito', 'subcuenta'],
        ['510539', 'Vacaciones',                              'debito', 'subcuenta'],
        ['510548', 'Aportes ARL',                             'debito', 'subcuenta'],
        ['510568', 'Aportes Salud Empleador (EPS)',           'debito', 'subcuenta'],
        ['510569', 'Aportes Pensión Empleador',               'debito', 'subcuenta'],
        ['510570', 'Aportes Caja de Compensación Familiar',   'debito', 'subcuenta'],
        ['510572', 'Aportes ICBF',                            'debito', 'subcuenta'],
        ['510575', 'Aportes SENA',                            'debito', 'subcuenta'],
    ];

    /** @var array<string, string> */
    private array $parametrizacion = [
        // GASTOS — contrapartida débito de las provisiones y aportes
        'nomina.cuenta_gasto_cesantias'         => '510530',
        'nomina.cuenta_gasto_int_cesantias'     => '510533',
        'nomina.cuenta_gasto_prima'             => '510536',
        'nomina.cuenta_gasto_vacaciones'        => '510539',
        'nomina.cuenta_gasto_horas_extra'       => '510515',
        'nomina.cuenta_gasto_salud_empleador'   => '510568',
        'nomina.cuenta_gasto_pension_empleador' => '510569',
        'nomina.cuenta_gasto_arl'               => '510548',
        'nomina.cuenta_gasto_ccf'               => '510570',
        'nomina.cuenta_gasto_icbf'              => '510572',
        'nomina.cuenta_gasto_sena'              => '510575',
    ];

    /** @var array<int, array{string,string,string,string,bool,bool,bool}> */
    private array $conceptosEmpleador = [
        // [codigo, nombre, tipo, subtipo, aplica_seg_social, aplica_retef, es_prest]
        ['EMP_SALUD',   'Aporte Salud Empleador 8.5%',  'aporte_empleador', 'salud_emp',   true,  false, false],
        ['EMP_PENSION', 'Aporte Pensión Empleador 12%', 'aporte_empleador', 'pension_emp', true,  false, false],
        ['EMP_ARL',     'ARL Empleador',                'aporte_empleador', 'arl',         true,  false, false],
        ['EMP_CCF',     'Caja de Compensación 4%',      'aporte_empleador', 'ccf',         false, false, false],
        ['EMP_SENA',    'Aporte SENA 2%',               'aporte_empleador', 'sena',        false, false, false],
        ['EMP_ICBF',    'Aporte ICBF 3%',               'aporte_empleador', 'icbf',        false, false, false],
    ];

    public function up(): void
    {
        $this->sembrarCuentasPuc();
        $this->sembrarParametrizacion();
        $this->sembrarConceptosEmpleador();
    }

    public function down(): void
    {
        ConceptoNomina::whereIn('codigo', array_column($this->conceptosEmpleador, 0))->delete();
        ParametrizacionContable::whereIn('clave', array_keys($this->parametrizacion))->delete();
        CuentaContable::whereIn('codigo', array_column($this->cuentasPuc, 0))->delete();
    }

    private function sembrarCuentasPuc(): void
    {
        // Resolver parent_id = 5105 (Gastos de Personal). Si no existe la
        // cuenta padre, abortar este paso silenciosamente — significa que
        // 2026_05_09_000003_rebuild_puc_completo aún no ha corrido.
        $parent5105 = CuentaContable::where('codigo', '5105')->first();
        if ($parent5105 === null) {
            logger()->warning('nomina_empleador: cuenta padre 5105 no existe, omitiendo PUC');
            return;
        }

        foreach ($this->cuentasPuc as [$codigo, $nombre, $naturaleza, $nivel]) {
            if (CuentaContable::where('codigo', $codigo)->exists()) {
                continue;
            }

            CuentaContable::create([
                'codigo'              => $codigo,
                'nombre'              => $nombre,
                'naturaleza'          => $naturaleza,
                'nivel'               => $nivel,
                'parent_id'           => $parent5105->id,
                'acepta_movimientos'  => true,
                'exige_tercero'       => false,
                'exige_centro_costo'  => false,
                'exige_base_impuesto' => false,
                'activo'              => true,
            ]);
        }
    }

    private function sembrarParametrizacion(): void
    {
        $cuentasIdx = CuentaContable::pluck('id', 'codigo')->toArray();

        foreach ($this->parametrizacion as $clave => $codigo) {
            if (ParametrizacionContable::where('clave', $clave)->where('activo', true)->exists()) {
                continue;
            }

            $cuentaId = $cuentasIdx[$codigo] ?? null;
            if ($cuentaId === null) {
                logger()->warning("nomina_empleador: cuenta {$codigo} no existe para clave '{$clave}'");
                continue;
            }

            ParametrizacionContable::create([
                'clave'              => $clave,
                'cuenta_contable_id' => $cuentaId,
                'descripcion'        => "Auto-configurado: {$clave} → [{$codigo}]",
                'activo'             => true,
            ]);
        }
    }

    private function sembrarConceptosEmpleador(): void
    {
        foreach ($this->conceptosEmpleador as [$codigo, $nombre, $tipo, $subtipo, $segSoc, $retef, $prest]) {
            ConceptoNomina::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'                  => $nombre,
                    'tipo'                    => $tipo,
                    'subtipo'                 => $subtipo,
                    'aplica_seguridad_social' => $segSoc,
                    'aplica_retefuente'       => $retef,
                    'es_prestacion_social'    => $prest,
                    'sistema'                 => true,
                    'activo'                  => true,
                ],
            );
        }
    }
};
