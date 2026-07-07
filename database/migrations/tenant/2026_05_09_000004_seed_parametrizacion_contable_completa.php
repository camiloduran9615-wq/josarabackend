<?php

declare(strict_types=1);

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use Illuminate\Database\Migrations\Migration;

/**
 * Siembra la parametrización contable canónica completa.
 *
 * Cada clave canónica mapea a la cuenta del PUC que le corresponde.
 * El ContabilizadorService usa estas claves para construir asientos
 * sin acoplarse al PUC concreto de cada tenant.
 *
 * Módulos cubiertos:
 *  • factura.*       → Ventas / Facturación electrónica
 *  • compra.*        → Compras / Documentos de ingreso
 *  • recibo_caja.*   → Recibos de caja (cobros)
 *  • nota_credito.*  → Notas crédito (devoluciones en ventas)
 *  • nota_debito.*   → Notas débito
 *  • nomina.*        → Nómina básica
 *  • cierre.*        → Asientos de cierre de ejercicio
 *
 * Es IDEMPOTENTE: no falla si las claves ya existen.
 */
return new class extends Migration
{
    /** clave => código_cuenta_puc */
    private array $parametrizacion = [

        // ── Ventas / Factura electrónica ──────────────────────────────────
        'factura.cuenta_cartera'             => '130505', // Clientes Nacionales
        'factura.cuenta_ingresos_ventas'     => '413505', // Venta de Mercancías
        'factura.cuenta_costo_ventas'        => '613505', // Costo de Ventas Mercancías
        'factura.cuenta_inventario'          => '143005', // Inventario de Mercancías
        'factura.cuenta_iva_generado_19'     => '240801', // IVA Generado en Ventas
        'factura.cuenta_iva_generado_5'      => '240801', // IVA Generado (misma cuenta, tarifa en condiciones)
        'factura.cuenta_retefuente_ventas'   => '135515', // RetefuentE Anticipo (activo)
        'factura.cuenta_reteica_ventas'      => '135518', // Anticipo ICA (activo)
        'factura.cuenta_descuento_ventas'    => '419505', // Devoluciones y Rebajas

        // ── Compras / Documentos de ingreso ───────────────────────────────
        'compra.cuenta_proveedor'            => '220505', // Proveedores Nacionales
        'compra.cuenta_inventario_merc'      => '143005', // Inventario de Mercancías
        'compra.cuenta_inventario_mp'        => '145505', // Materias Primas
        'compra.cuenta_inventario_pt'        => '146505', // Productos Terminados
        'compra.cuenta_iva_descontable'      => '240810', // IVA Descontable en Compras
        'compra.cuenta_retefuente'           => '236505', // Retefuente Compras Generales
        'compra.cuenta_retefuente_honorarios'=> '236540', // Retefuente Honorarios
        'compra.cuenta_reteica'              => '236801', // ReteICA
        'compra.cuenta_caja'                 => '110505', // Caja General
        'compra.cuenta_banco'                => '111005', // Banco Cuenta de Ahorros
        'compra.cuenta_gasto_general'        => '519505', // Papelería y Útiles (gasto genérico)
        'compra.cuenta_flete'                => '525010', // Fletes de Importación

        // ── Recibo de Caja (cobros a clientes) ────────────────────────────
        'recibo_caja.cuenta_caja'            => '110505', // Caja General
        'recibo_caja.cuenta_banco'           => '111005', // Banco Cuenta de Ahorros
        'recibo_caja.cuenta_cartera'         => '130505', // Clientes Nacionales (débito cartera)
        'recibo_caja.cuenta_descuento'       => '530515', // Descuentos Comerciales Concedidos

        // ── Nota Crédito (devoluciones en ventas) ─────────────────────────
        'nota_credito.cuenta_cartera'        => '130505', // Clientes Nacionales
        'nota_credito.cuenta_ingresos'       => '413505', // Venta de Mercancías (reverso)
        'nota_credito.cuenta_inventario'     => '143005', // Inventario de Mercancías (re-entrada)
        'nota_credito.cuenta_costo'          => '613505', // Costo de Ventas (reverso)
        'nota_credito.cuenta_iva'            => '240801', // IVA Generado (reverso)

        // ── Nota Débito ───────────────────────────────────────────────────
        'nota_debito.cuenta_cartera'         => '130505', // Clientes Nacionales
        'nota_debito.cuenta_ingreso_interes' => '421005', // Intereses
        'nota_debito.cuenta_iva'             => '240801', // IVA Generado

        // ── Nómina básica ─────────────────────────────────────────────────
        'nomina.cuenta_sueldos'              => '510506', // Sueldos y Salarios
        'nomina.cuenta_auxilio_transporte'   => '510527', // Auxilio de Transporte
        'nomina.cuenta_salarios_por_pagar'   => '250505', // Sueldos y Salarios × pagar
        'nomina.cuenta_cesantias'            => '251005', // Cesantías
        'nomina.cuenta_intereses_cesantias'  => '251505', // Intereses Cesantías
        'nomina.cuenta_prima'                => '252005', // Prima de Servicios
        'nomina.cuenta_vacaciones'           => '252505', // Vacaciones
        'nomina.cuenta_aporte_salud'         => '237005', // Aportes a Salud
        'nomina.cuenta_aporte_pension'       => '237010', // Aportes a Pensión
        'nomina.cuenta_aporte_arl'           => '237015', // Aportes a ARL
        'nomina.cuenta_parafiscal_sena'      => '237020', // SENA
        'nomina.cuenta_parafiscal_icbf'      => '237025', // ICBF
        'nomina.cuenta_caja_compensacion'    => '237030', // Caja de Compensación

        // ── Cierre de ejercicio ───────────────────────────────────────────
        'cierre.cuenta_utilidad_ejercicio'   => '360505', // Utilidad del Ejercicio
        'cierre.cuenta_perdida_ejercicio'    => '361005', // Pérdida del Ejercicio
        'cierre.cuenta_utilidades_ant'       => '370505', // Utilidades Acumuladas
    ];

    public function up(): void
    {
        // Índice código → id
        $cuentasIdx = CuentaContable::pluck('id', 'codigo')->toArray();

        foreach ($this->parametrizacion as $clave => $codigoCuenta) {
            // Idempotente: no duplicar si ya existe activa
            if (ParametrizacionContable::where('clave', $clave)->where('activo', true)->exists()) {
                continue;
            }

            $cuentaId = $cuentasIdx[$codigoCuenta] ?? null;
            if ($cuentaId === null) {
                // La cuenta no existe en el PUC → registrar aviso y continuar
                logger()->warning("Parametrización: cuenta {$codigoCuenta} no existe para clave '{$clave}'");
                continue;
            }

            ParametrizacionContable::create([
                'clave'              => $clave,
                'cuenta_contable_id' => $cuentaId,
                'descripcion'        => "Auto-configurado: {$clave} → [{$codigoCuenta}]",
                'activo'             => true,
            ]);
        }
    }

    public function down(): void
    {
        ParametrizacionContable::whereIn('clave', array_keys($this->parametrizacion))->delete();
    }
};
