<?php

declare(strict_types=1);

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use Illuminate\Database\Migrations\Migration;

/**
 * Siembra las claves canónicas de parametrización contable para el módulo
 * de Compras / Documentos de Ingreso.
 *
 * Mapa (clave → cuenta PUC):
 * ┌──────────────────────────────────┬──────────┬───────────────────────────────────────────────┐
 * │ Clave                            │ Cuenta   │ Descripción                                   │
 * ├──────────────────────────────────┼──────────┼───────────────────────────────────────────────┤
 * │ compra.cuenta_proveedor          │ 220505   │ Proveedores nacionales (crédito en compra)     │
 * │ compra.cuenta_inventario_merc    │ 143005   │ Inventario de Mercancías (débito)              │
 * │ compra.cuenta_inventario_mp      │ 145505   │ Materias Primas (débito)                      │
 * │ compra.cuenta_iva_descontable    │ 240810   │ IVA descontable en compras (débito)           │
 * │ compra.cuenta_retefuente         │ 236540   │ ReteFuente compras (crédito)                  │
 * │ compra.cuenta_reteica            │ 236801   │ ReteICA compras (crédito)                     │
 * │ compra.cuenta_caja               │ 110505   │ Caja general (crédito en compra de contado)   │
 * │ compra.cuenta_gasto_general      │ 519500   │ Gastos diversos (débito, para tipo=gasto)     │
 * └──────────────────────────────────┴──────────┴───────────────────────────────────────────────┘
 *
 * NOTA: Esta migración es IDEMPOTENTE — no falla si las cuentas ya existen o
 * si las parametrizaciones ya fueron creadas por una instalación previa.
 */
return new class extends Migration
{
    /** Cuentas PUC mínimas que necesitamos para compras */
    private array $cuentas = [
        // Grupo / Subcuenta para IVA descontable
        ['codigo' => '2408',   'nombre' => 'IVA por Pagar',                          'nivel' => 'cuenta',    'parent_codigo' => '24',   'naturaleza' => 'credito'],
        ['codigo' => '240810', 'nombre' => 'IVA Descontable en Compras',             'nivel' => 'auxiliar',  'parent_codigo' => '2408', 'naturaleza' => 'credito'],
        // Proveedores
        ['codigo' => '2205',   'nombre' => 'Proveedores',                            'nivel' => 'cuenta',    'parent_codigo' => '22',   'naturaleza' => 'credito'],
        ['codigo' => '220505', 'nombre' => 'Proveedores Nacionales',                 'nivel' => 'subcuenta', 'parent_codigo' => '2205', 'naturaleza' => 'credito'],
        // Materias Primas
        ['codigo' => '1455',   'nombre' => 'Materias Primas',                        'nivel' => 'cuenta',    'parent_codigo' => '14',   'naturaleza' => 'debito'],
        ['codigo' => '145505', 'nombre' => 'Materias Primas - Inventario',           'nivel' => 'subcuenta', 'parent_codigo' => '1455', 'naturaleza' => 'debito'],
        // Retenciones
        ['codigo' => '2365',   'nombre' => 'Retención en la Fuente',                 'nivel' => 'cuenta',    'parent_codigo' => '23',   'naturaleza' => 'credito'],
        ['codigo' => '236540', 'nombre' => 'Retención en la Fuente Compras',         'nivel' => 'subcuenta', 'parent_codigo' => '2365', 'naturaleza' => 'credito'],
        ['codigo' => '2368',   'nombre' => 'Industria y Comercio',                   'nivel' => 'cuenta',    'parent_codigo' => '23',   'naturaleza' => 'credito'],
        ['codigo' => '236801', 'nombre' => 'ReteICA Compras',                        'nivel' => 'subcuenta', 'parent_codigo' => '2368', 'naturaleza' => 'credito'],
        // Caja
        ['codigo' => '1105',   'nombre' => 'Caja',                                   'nivel' => 'cuenta',    'parent_codigo' => '11',   'naturaleza' => 'debito'],
        ['codigo' => '110505', 'nombre' => 'Caja General',                           'nivel' => 'subcuenta', 'parent_codigo' => '1105', 'naturaleza' => 'debito'],
        // Gastos generales
        ['codigo' => '5195',   'nombre' => 'Diversos',                               'nivel' => 'cuenta',    'parent_codigo' => '51',   'naturaleza' => 'debito'],
        ['codigo' => '519500', 'nombre' => 'Gastos Generales Compras',               'nivel' => 'subcuenta', 'parent_codigo' => '5195', 'naturaleza' => 'debito'],
    ];

    /** Mapa clave → código cuenta */
    private array $parametrizaciones = [
        'compra.cuenta_proveedor'       => '220505',
        'compra.cuenta_inventario_merc' => '143005',   // ya existe por migración anterior
        'compra.cuenta_inventario_mp'   => '145505',
        'compra.cuenta_iva_descontable' => '240810',
        'compra.cuenta_retefuente'      => '236540',
        'compra.cuenta_reteica'         => '236801',
        'compra.cuenta_caja'            => '110505',
        'compra.cuenta_gasto_general'   => '519500',
    ];

    public function up(): void
    {
        // 1. Crear cuentas PUC que falten
        foreach ($this->cuentas as $row) {
            if (CuentaContable::where('codigo', $row['codigo'])->exists()) {
                continue;
            }

            $parentId = null;
            if (! empty($row['parent_codigo'])) {
                $parent   = CuentaContable::where('codigo', $row['parent_codigo'])->first();
                $parentId = $parent?->id;
            }

            CuentaContable::create([
                'codigo'               => $row['codigo'],
                'nombre'               => $row['nombre'],
                'naturaleza'           => $row['naturaleza'],
                'nivel'                => $row['nivel'],
                'parent_id'            => $parentId,
                'acepta_movimientos'   => in_array($row['nivel'], ['subcuenta', 'auxiliar'], true),
                'exige_tercero'        => in_array($row['codigo'], ['220505', '236540', '236801'], true),
                'exige_base_impuesto'  => false,
            ]);
        }

        // 2. Crear parametrizaciones que falten
        foreach ($this->parametrizaciones as $clave => $codigoCuenta) {
            if (ParametrizacionContable::where('clave', $clave)->where('activo', true)->exists()) {
                continue;
            }

            $cuenta = CuentaContable::where('codigo', $codigoCuenta)->first();
            if ($cuenta === null) {
                // No fallar si la cuenta no se pudo crear (parent inexistente)
                continue;
            }

            ParametrizacionContable::create([
                'clave'             => $clave,
                'cuenta_contable_id'=> $cuenta->id,
                'descripcion'       => "Auto-configurado para módulo Compras ({$codigoCuenta})",
                'activo'            => true,
            ]);
        }
    }

    public function down(): void
    {
        ParametrizacionContable::whereIn('clave', array_keys($this->parametrizaciones))->delete();

        foreach (array_reverse($this->cuentas) as $row) {
            CuentaContable::where('codigo', $row['codigo'])->delete();
        }
    }
};
