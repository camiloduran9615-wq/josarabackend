<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use Illuminate\Database\Seeder;

/**
 * Siembra las claves canónicas de parametrización contable por módulo.
 *
 * Idempotente: updateOrCreate sobre `clave`.
 * Depende de TenantPucSeeder (las cuentas deben existir).
 */
final class TenantParametrizacionSeeder extends Seeder
{
    /**
     * [clave, codigo_cuenta, descripcion]
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const PARAMETROS = [
        // ── Módulo Ventas / Facturación ───────────────────────────────────
        ['venta.cuenta_cxc',            '130505', 'CxC Clientes Nacionales (débito en facturas)'],
        ['venta.cuenta_ingresos',       '413505', 'Ingresos por Ventas de Mercancías'],
        // BUG-010: IVA generado discriminado por tarifa (Formulario 300 DIAN).
        // venta.cuenta_iva_generado se mantiene como fallback para tarifa 19%
        // (tarifa más común y por compatibilidad con código anterior al fix).
        ['venta.cuenta_iva_generado',   '240805', 'IVA por Pagar — Ventas Tarifa 19% (fallback)'],
        ['venta.cuenta_iva_generado_19','240805', 'IVA por Pagar — Ventas Tarifa 19%'],
        ['venta.cuenta_iva_generado_5', '240802', 'IVA por Pagar — Ventas Tarifa 5%'],
        ['venta.cuenta_costo_ventas',   '613505', 'Costo de Ventas — Mercancías'],
        ['factura.cuenta_costo_ventas', '613505', 'Costo de Ventas en Factura (alias de venta.cuenta_costo_ventas)'],

        // ── Módulo Compras ────────────────────────────────────────────────
        ['compra.cuenta_proveedor',     '220505', 'Proveedores Nacionales (crédito en compras)'],
        ['compra.cuenta_inventario',    '143005', 'Inventario de Mercancías (débito en compras)'],
        ['compra.cuenta_iva_descontable','240810', 'IVA Descontable en Compras (débito en compras)'],
        ['compra.cuenta_retefuente',    '236540', 'Retención en la Fuente — Retenida en Compras'],
        ['compra.cuenta_reteica',       '236801', 'ReteICA — Retenida en Compras'],

        // ── Módulo Tesorería / Recibo de Caja ────────────────────────────
        ['recibo.cuenta_caja',          '110505', 'Caja General (débito en recibos de caja)'],
        ['recibo.cuenta_cxc',           '130505', 'CxC Clientes Nacionales (crédito en recibos de caja)'],

        // ── Módulo Gastos / Egresos ───────────────────────────────────────
        ['egreso.cuenta_caja',          '110505', 'Caja General (crédito en egresos de caja)'],
        ['egreso.cuenta_banco',         '111005', 'Bancos — Moneda Nacional (crédito en egresos bancarios)'],
    ];

    public function run(): void
    {
        $cuentas = CuentaContable::whereIn('codigo', array_column(self::PARAMETROS, 1))
            ->pluck('id', 'codigo');

        foreach (self::PARAMETROS as [$clave, $codigoCuenta, $descripcion]) {
            $cuentaId = $cuentas[$codigoCuenta] ?? null;

            if ($cuentaId === null) {
                $this->command->warn("Cuenta {$codigoCuenta} no encontrada — clave '{$clave}' omitida.");
                continue;
            }

            ParametrizacionContable::updateOrCreate(
                ['clave' => $clave],
                [
                    'cuenta_contable_id' => $cuentaId,
                    'descripcion'        => $descripcion,
                    'activo'             => true,
                ],
            );
        }

        $this->command->info(sprintf('Parametrización sembrada: %d claves.', count(self::PARAMETROS)));
    }
}
