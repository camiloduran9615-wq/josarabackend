<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende las tablas de DocumentoIngreso e InventarioMovimiento para
 * soportar el flujo completo de compra → inventario → asiento contable.
 *
 * Cambios:
 *  1. documento_ingreso_items: agrega producto_id (nullable para compras de gasto/servicio)
 *  2. inventario_movimientos:  agrega documento_ingreso_id + tipo_comprobante
 *  3. documentos_ingreso:      agrega asiento_id (FK al asiento generado automáticamente)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Items → vinculación al catálogo de productos
        Schema::table('documento_ingreso_items', function (Blueprint $table): void {
            // nullable: los ítems de tipo "gasto/servicio" no tienen producto
            $table->foreignUuid('producto_id')
                  ->nullable()
                  ->after('documento_ingreso_id')
                  ->constrained('productos')
                  ->nullOnDelete();
        });

        // 2. Movimientos de inventario → trazabilidad con documento de compra
        Schema::table('inventario_movimientos', function (Blueprint $table): void {
            $table->foreignUuid('documento_ingreso_id')
                  ->nullable()
                  ->after('factura_id')
                  ->constrained('documentos_ingreso')
                  ->nullOnDelete();

            // Coste unitario real registrado en la compra (puede diferir del precio_compra del producto)
            $table->decimal('costo_unitario', 15, 4)
                  ->default(0)
                  ->after('precio_unitario')
                  ->comment('Costo unitario real en la transacción de compra');
        });

        // 3. DocumentoIngreso → FK al asiento generado (null hasta que se contabilice)
        Schema::table('documentos_ingreso', function (Blueprint $table): void {
            $table->foreignUuid('asiento_id')
                  ->nullable()
                  ->after('estado')
                  ->constrained('asientos')
                  ->nullOnDelete()
                  ->comment('Asiento contable generado automáticamente al registrar la compra');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_ingreso', function (Blueprint $table): void {
            $table->dropForeign(['asiento_id']);
            $table->dropColumn('asiento_id');
        });

        Schema::table('inventario_movimientos', function (Blueprint $table): void {
            $table->dropForeign(['documento_ingreso_id']);
            $table->dropColumn(['documento_ingreso_id', 'costo_unitario']);
        });

        Schema::table('documento_ingreso_items', function (Blueprint $table): void {
            $table->dropForeign(['producto_id']);
            $table->dropColumn('producto_id');
        });
    }
};
