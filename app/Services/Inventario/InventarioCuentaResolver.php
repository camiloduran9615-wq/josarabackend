<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Tenant\Bodega;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Producto;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use App\Services\Contabilizacion\ParametrizacionRepository;

/**
 * Resuelve la cuenta contable de inventario para un producto/bodega
 * siguiendo el orden de prioridad del sistema:
 *
 *  1. bodega.inventario_cuenta_id           (override específico de la bodega)
 *  2. producto.inventario_cuenta_id         (cuenta explícita del producto)
 *  3. categoria.inventario_cuenta_id        (fallback de la categoría)
 *  4. parametrizacion[tipo_categoria]       (fallback global de la empresa)
 *
 * Si ninguna capa resuelve la cuenta → lanza ParametrizacionFaltanteException.
 */
final class InventarioCuentaResolver
{
    public function __construct(
        private readonly ParametrizacionRepository $params,
    ) {}

    /**
     * @throws ParametrizacionFaltanteException
     */
    public function resolverParaEntrada(
        Producto $producto,
        ?Bodega  $bodega = null,
    ): CuentaContable {
        // 1. Bodega con cuenta override
        if ($bodega?->inventario_cuenta_id) {
            return CuentaContable::findOrFail($bodega->inventario_cuenta_id);
        }

        // 2. Producto con cuenta explícita
        if ($producto->inventario_cuenta_id) {
            return CuentaContable::findOrFail($producto->inventario_cuenta_id);
        }

        // 3. Categoría del producto con cuenta configurada
        $categoria = $producto->categoria;
        if ($categoria?->inventario_cuenta_id) {
            return CuentaContable::findOrFail($categoria->inventario_cuenta_id);
        }

        // 4. Parametrización global según tipo de categoría
        $clave = $categoria?->claveParametrizacionInventario()
                 ?? 'compra.cuenta_inventario_merc';

        return $this->params->cuenta($clave);    // ← método correcto
    }

    /**
     * Resuelve la cuenta de COSTO DE VENTAS para un producto.
     * Usado al generar el asiento 6135/1435 en facturas de venta.
     *
     * @throws ParametrizacionFaltanteException
     */
    public function resolverCostoVentas(Producto $producto): CuentaContable
    {
        $categoria = $producto->categoria;

        if ($categoria?->costo_ventas_cuenta_id) {
            return CuentaContable::findOrFail($categoria->costo_ventas_cuenta_id);
        }

        return $this->params->cuenta('factura.cuenta_costo_ventas');    // ← método correcto
    }
}
