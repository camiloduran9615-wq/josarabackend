<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende la tabla categorias para que cada categoría lleve:
 *
 * - tipo: mercancía | materia_prima | producto_proceso | producto_terminado | servicio | activo_fijo
 * - inventario_cuenta_id:       cuenta PUC del inventario (Ej: 143505, 145505)
 * - ingresos_cuenta_id:         cuenta de ingresos al vender (Ej: 413505)
 * - costo_ventas_cuenta_id:     costo de ventas (Ej: 613505)
 * - devolucion_compras_cuenta_id
 * - devolucion_ventas_cuenta_id
 *
 * Estas cuentas son el fallback cuando el producto o bodega no tienen
 * una cuenta explícita. El orden de resolución es:
 *   bodega.inventario_cuenta_id
 *   > producto.inventario_cuenta_id
 *   > categoria.inventario_cuenta_id
 *   > parametrizacion['compra.cuenta_inventario_{tipo}']
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->enum('tipo', [
                'mercancia',
                'materia_prima',
                'producto_proceso',
                'producto_terminado',
                'servicio',
                'activo_fijo',
            ])->default('mercancia')->after('nombre');

            $table->foreignUuid('inventario_cuenta_id')
                  ->nullable()
                  ->constrained('cuentas_contables')
                  ->after('tipo');

            $table->foreignUuid('ingresos_cuenta_id')
                  ->nullable()
                  ->constrained('cuentas_contables')
                  ->after('inventario_cuenta_id');

            $table->foreignUuid('costo_ventas_cuenta_id')
                  ->nullable()
                  ->constrained('cuentas_contables')
                  ->after('ingresos_cuenta_id');

            $table->foreignUuid('devolucion_compras_cuenta_id')
                  ->nullable()
                  ->constrained('cuentas_contables')
                  ->after('costo_ventas_cuenta_id');

            $table->foreignUuid('devolucion_ventas_cuenta_id')
                  ->nullable()
                  ->constrained('cuentas_contables')
                  ->after('devolucion_compras_cuenta_id');

            $table->boolean('activa')->default(true)->after('devolucion_ventas_cuenta_id');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn([
                'tipo',
                'inventario_cuenta_id',
                'ingresos_cuenta_id',
                'costo_ventas_cuenta_id',
                'devolucion_compras_cuenta_id',
                'devolucion_ventas_cuenta_id',
                'activa',
            ]);
        });
    }
};
