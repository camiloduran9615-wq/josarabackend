<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende factura_items para multi-bodega y trazabilidad de costo.
 *
 * - bodega_id:          bodega desde la que sale el producto al vender
 * - costo_unitario_cpp: snapshot del Costo Promedio Ponderado al momento de la venta
 *                       → permite reconstruir el asiento 6135/1435 aunque el CPP cambie después
 *
 * El costo de ventas se genera contablemente usando este campo:
 *   Débito  6135xx (costo de ventas) = cantidad × costo_unitario_cpp
 *   Crédito 1435xx (inventario)      = cantidad × costo_unitario_cpp
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factura_items', function (Blueprint $table) {
            $table->foreignUuid('bodega_id')
                  ->nullable()
                  ->constrained('bodegas')
                  ->after('codigo_referencia');

            // Snapshot CPP en el momento de la venta (inmutable después de guardar)
            $table->decimal('costo_unitario_cpp', 15, 4)
                  ->nullable()
                  ->after('bodega_id')
                  ->comment('CPP vigente al momento de la venta — para asiento 6135/1435');
        });
    }

    public function down(): void
    {
        Schema::table('factura_items', function (Blueprint $table) {
            $table->dropColumn(['bodega_id', 'costo_unitario_cpp']);
        });
    }
};
