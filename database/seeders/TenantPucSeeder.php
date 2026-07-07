<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant\CuentaContable;
use Illuminate\Database\Seeder;

/**
 * 51 cuentas del PUC Decreto 2650/2420 más usadas (servicios + comercio).
 *
 * Jerarquía: clase → grupo → cuenta → subcuenta
 * Idempotente: updateOrCreate sobre `codigo` (PK natural del PUC).
 *
 * Regla de sistema/editable:
 *   - grupos 21-29 (pasivo estructural), 36-39 (resultados), 51 (gastos admin),
 *     cuenta 5905 y sus hijos → sistema=true, editable=false
 *   - resto → sistema=false, editable=true
 *
 * Regla de acepta_movimientos:
 *   - subcuentas (6 dígitos) → true
 *   - clase/grupo/cuenta     → false (usuarios crean auxiliares debajo)
 */
final class TenantPucSeeder extends Seeder
{
    /**
     * Columnas: [nivel, codigo, nombre, naturaleza, clase_int, parent_codigo,
     *            clasificacion_balance, clasificacion_pyg, sistema,
     *            nif_referencia, exige_tercero, exige_base_impuesto]
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:int,5:string|null,6:string,7:string,8:bool,9:string|null,10:bool,11:bool}>
     */
    private const PLAN = [
        // ── CLASES ────────────────────────────────────────────────────────────
        ['clase', '1', 'Activo',                                              'debito',  1, null, 'na',          'na',            false, 'NIC-1',  false, false],
        ['clase', '2', 'Pasivo',                                              'credito', 2, null, 'na',          'na',            false, 'NIC-1',  false, false],
        ['clase', '3', 'Patrimonio',                                          'credito', 3, null, 'na',          'na',            false, 'NIC-1',  false, false],
        ['clase', '4', 'Ingresos',                                            'credito', 4, null, 'na',          'na',            false, 'NIIF-15',false, false],
        ['clase', '5', 'Gastos',                                              'debito',  5, null, 'na',          'na',            false, 'NIC-1',  false, false],
        ['clase', '6', 'Costos de Ventas y de Prestación de Servicios',       'debito',  6, null, 'na',          'na',            false, 'NIC-2',  false, false],

        // ── GRUPOS ────────────────────────────────────────────────────────────
        ['grupo', '11', 'Efectivo y Equivalentes de Efectivo',                'debito',  1, '1', 'corriente',   'na',            false, 'NIC-7',  false, false],
        ['grupo', '13', 'Deudores Comerciales y Otras Cuentas por Cobrar',    'debito',  1, '1', 'corriente',   'na',            false, 'NIIF-9', false, false],
        ['grupo', '14', 'Inventarios',                                        'debito',  1, '1', 'corriente',   'na',            false, 'NIC-2',  false, false],
        ['grupo', '15', 'Propiedades, Planta y Equipo',                       'debito',  1, '1', 'no_corriente','na',            false, 'NIC-16', false, false],
        ['grupo', '21', 'Obligaciones Financieras',                           'credito', 2, '2', 'no_corriente','na',            true,  'NIIF-9', false, false],
        ['grupo', '22', 'Proveedores',                                        'credito', 2, '2', 'corriente',   'na',            true,  'NIIF-9', false, false],
        ['grupo', '23', 'Cuentas por Pagar',                                  'credito', 2, '2', 'corriente',   'na',            true,  'NIIF-9', false, false],
        ['grupo', '24', 'Impuestos, Gravámenes y Tasas',                      'credito', 2, '2', 'corriente',   'na',            true,  'NIC-12', false, false],
        ['grupo', '25', 'Obligaciones Laborales',                             'credito', 2, '2', 'corriente',   'na',            true,  'NIC-19', false, false],
        ['grupo', '31', 'Capital Social',                                     'credito', 3, '3', 'na',          'na',            false, 'NIC-1',  false, false],
        ['grupo', '36', 'Resultados del Ejercicio',                           'credito', 3, '3', 'na',          'na',            true,  'NIC-1',  false, false],
        ['grupo', '41', 'Ingresos Operacionales',                             'credito', 4, '4', 'na',          'operacional',   false, 'NIIF-15',false, false],
        ['grupo', '51', 'Gastos Operacionales de Administración',             'debito',  5, '5', 'na',          'operacional',   true,  'NIC-1',  false, false],
        ['grupo', '59', 'Ganancias y Pérdidas',                               'credito', 5, '5', 'na',          'na',            true,  'NIC-1',  false, false],
        ['grupo', '61', 'Costos de Ventas y de Prestación de Servicios',      'debito',  6, '6', 'na',          'operacional',   false, 'NIC-2',  false, false],

        // ── CUENTAS (4 dígitos) ───────────────────────────────────────────────
        ['cuenta', '1105', 'Caja',                                            'debito',  1, '11', 'corriente',  'na',            false, 'NIC-7',  false, false],
        ['cuenta', '1110', 'Bancos',                                          'debito',  1, '11', 'corriente',  'na',            false, 'NIC-7',  false, false],
        ['cuenta', '1305', 'Clientes',                                        'debito',  1, '13', 'corriente',  'na',            false, 'NIIF-9', true,  false],
        ['cuenta', '1430', 'Mercancías no Fabricadas por la Empresa',         'debito',  1, '14', 'corriente',  'na',            false, 'NIC-2',  false, false],
        ['cuenta', '1516', 'Construcciones y Edificaciones',                  'debito',  1, '15', 'no_corriente','na',           false, 'NIC-16', false, false],
        ['cuenta', '1524', 'Equipo de Oficina',                               'debito',  1, '15', 'no_corriente','na',           false, 'NIC-16', false, false],
        ['cuenta', '1592', 'Depreciación Acumulada (CR)',                     'credito', 1, '15', 'no_corriente','na',           false, 'NIC-16', false, false],
        ['cuenta', '2105', 'Bancos Nacionales',                               'credito', 2, '21', 'no_corriente','na',           true,  'NIIF-9', false, false],
        ['cuenta', '2205', 'Proveedores Nacionales',                          'credito', 2, '22', 'corriente',  'na',            true,  'NIIF-9', true,  false],
        ['cuenta', '2335', 'Costos y Gastos por Pagar',                       'credito', 2, '23', 'corriente',  'na',            true,  'NIIF-9', true,  false],
        ['cuenta', '2365', 'Retención en la Fuente',                          'credito', 2, '23', 'corriente',  'na',            true,  'NIC-12', true,  true],
        ['cuenta', '2366', 'Impuesto a las Ventas Retenido (ReteIVA)',        'credito', 2, '23', 'corriente',  'na',            true,  'NIC-12', false, false],
        ['cuenta', '2368', 'ICA Retenido por Pagar',                          'credito', 2, '23', 'corriente',  'na',            true,  'NIC-12', true,  false],
        ['cuenta', '2408', 'Impuesto sobre las Ventas por Pagar (IVA)',       'credito', 2, '24', 'corriente',  'na',            true,  'NIC-12', false, false],
        ['cuenta', '2416', 'Impuesto de Industria y Comercio (ICA)',          'credito', 2, '24', 'corriente',  'na',            true,  'NIC-12', false, false],
        ['cuenta', '3105', 'Capital Suscrito y Pagado',                       'credito', 3, '31', 'na',         'na',            false, 'NIC-1',  false, false],
        ['cuenta', '3605', 'Utilidad del Ejercicio',                          'credito', 3, '36', 'na',         'na',            true,  'NIC-1',  false, false],
        ['cuenta', '4135', 'Ingresos por Ventas — Comercio al por Mayor/Menor','credito',4, '41', 'na',         'operacional',   false, 'NIIF-15',false, false],
        ['cuenta', '5105', 'Gastos de Personal',                              'debito',  5, '51', 'na',         'operacional',   true,  'NIC-19', false, false],
        ['cuenta', '5110', 'Honorarios',                                      'debito',  5, '51', 'na',         'operacional',   true,  'NIC-1',  true,  false],
        ['cuenta', '5120', 'Arrendamientos',                                  'debito',  5, '51', 'na',         'operacional',   true,  'NIC-1',  true,  false],
        ['cuenta', '5905', 'Ganancias y Pérdidas',                            'credito', 5, '59', 'na',         'na',            true,  'NIC-1',  false, false],
        ['cuenta', '6135', 'Costo de Ventas — Comercio al por Mayor/Menor',   'debito',  6, '61', 'na',         'operacional',   false, 'NIC-2',  false, false],

        // ── SUBCUENTAS (6 dígitos) ────────────────────────────────────────────
        ['subcuenta', '110505', 'Caja General',                               'debito',  1, '1105', 'corriente', 'na',           false, 'NIC-7',  false, false],
        ['subcuenta', '111005', 'Bancos — Moneda Nacional',                   'debito',  1, '1110', 'corriente', 'na',           false, 'NIC-7',  false, false],
        ['subcuenta', '130505', 'Clientes Nacionales',                        'debito',  1, '1305', 'corriente', 'na',           false, 'NIIF-9', true,  false],
        ['subcuenta', '220505', 'Proveedores Nacionales',                     'credito', 2, '2205', 'corriente', 'na',           true,  'NIIF-9', true,  false],
        ['subcuenta', '236505', 'Retención en la Fuente — Terceros (DIAN)',     'credito', 2, '2365', 'corriente', 'na',           true,  'NIC-12', true,  true],
        ['subcuenta', '236540', 'Retención en la Fuente — Retenida en Compras','credito',2, '2365', 'corriente', 'na',           true,  'NIC-12', true,  true],
        ['subcuenta', '236605', 'ReteIVA — Retenida en Compras',              'credito', 2, '2366', 'corriente', 'na',           true,  'NIC-12', true,  false],
        ['subcuenta', '236801', 'ReteICA — Retenida en Compras',              'credito', 2, '2368', 'corriente', 'na',           true,  'NIC-12', true,  false],
        // IVA generado por tarifa (discriminado para Formulario 300 DIAN).
        // BUG-010: antes una sola 240805 acumulaba todas las tarifas dificultando la declaración.
        ['subcuenta', '240802', 'IVA por Pagar — Ventas Tarifa 5%',          'credito', 2, '2408', 'corriente', 'na',           true,  'NIC-12', false, false],
        ['subcuenta', '240805', 'IVA por Pagar — Ventas Tarifa 19%',         'credito', 2, '2408', 'corriente', 'na',           true,  'NIC-12', false, false],
        ['subcuenta', '143005', 'Inventario de Mercancías',                    'debito',  1, '1430', 'corriente', 'na',           false, 'NIC-2',  false, false],
        ['subcuenta', '240810', 'IVA Descontable en Compras',                 'debito',  2, '2408', 'corriente', 'na',           true,  'NIC-12', false, false],
        ['subcuenta', '413505', 'Ventas de Mercancías',                       'credito', 4, '4135', 'na',        'operacional',  false, 'NIIF-15',false, false],
        ['subcuenta', '360505', 'Utilidad del Ejercicio — Resultado',          'credito', 3, '3605', 'na',        'na',           true,  'NIC-1',  false, false],
        ['subcuenta', '590505', 'Ganancias y Pérdidas del Ejercicio',         'credito', 5, '5905', 'na',        'na',           true,  'NIC-1',  false, false],
        ['subcuenta', '613505', 'Costo de Ventas — Mercancías',               'debito',  6, '6135', 'na',        'operacional',  false, 'NIC-2',  false, false],
    ];

    public function run(): void
    {
        /** @var array<string, string> $idPorCodigo */
        $idPorCodigo = [];

        foreach (self::PLAN as [
            $nivel, $codigo, $nombre, $naturaleza, $claseInt, $parentCodigo,
            $clasifBalance, $clasifPyg, $sistema,
            $nifRef, $exigeTercero, $exigeBase,
        ]) {
            $esSistema   = $sistema;
            $esSubcuenta = ($nivel === 'subcuenta');

            $cuenta = CuentaContable::query()->updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'                 => $nombre,
                    'naturaleza'             => $naturaleza,
                    'nivel'                  => $nivel,
                    'clase'                  => $claseInt,
                    'parent_id'              => $parentCodigo !== null ? ($idPorCodigo[$parentCodigo] ?? null) : null,
                    'clasificacion_balance'  => $clasifBalance,
                    'clasificacion_pyg'      => $clasifPyg,
                    'sistema'                => $esSistema,
                    'editable'               => ! $esSistema,
                    'nif_referencia'         => $nifRef,
                    'exige_tercero'          => $exigeTercero,
                    'exige_centro_costo'     => false,
                    'exige_base_impuesto'    => $exigeBase,
                    'acepta_movimientos'     => $esSubcuenta,
                    'activo'                 => true,
                ],
            );

            $idPorCodigo[$codigo] = (string) $cuenta->id;
        }

        $this->command->info(sprintf(
            'PUC sembrado: %d cuentas (%d sistema).',
            count(self::PLAN),
            count(array_filter(self::PLAN, static fn (array $r): bool => $r[8])),
        ));
    }
}
