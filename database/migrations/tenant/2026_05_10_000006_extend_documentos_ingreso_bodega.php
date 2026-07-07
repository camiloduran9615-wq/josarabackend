<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende documentos_ingreso y documento_ingreso_items para multi-bodega.
 *
 * Encabezado: sucursal_id, centro_costo_id
 * Ítem:       bodega_id (a qué bodega ingresa el producto),
 *             tipo_linea (producto | gasto | activo_fijo)
 *             — los ítems de tipo 'gasto' NO tocan inventario
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_ingreso', function (Blueprint $table) {
            $table->foreignUuid('sucursal_id')
                  ->nullable()
                  ->constrained('sucursales')
                  ->after('tercero_id');

            $table->foreignUuid('centro_costo_id')
                  ->nullable()
                  ->constrained('centros_costo')
                  ->after('sucursal_id');
        });

        Schema::table('documento_ingreso_items', function (Blueprint $table) {
            $table->foreignUuid('bodega_id')
                  ->nullable()
                  ->constrained('bodegas')
                  ->after('producto_id');

            $table->enum('tipo_linea', ['producto', 'gasto', 'activo_fijo'])
                  ->default('producto')
                  ->after('bodega_id');
        });
    }

    public function down(): void
    {
        Schema::table('documento_ingreso_items', function (Blueprint $table) {
            $table->dropColumn(['bodega_id', 'tipo_linea']);
        });

        Schema::table('documentos_ingreso', function (Blueprint $table) {
            $table->dropColumn(['sucursal_id', 'centro_costo_id']);
        });
    }
};
