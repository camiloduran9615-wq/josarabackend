<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende inventario_movimientos para soportar KARDEX completo:
 *
 * - bodega_id / bodega_destino_id: bodega origen y destino (traslados)
 * - saldo_*_despues: snapshot pre-calculado para KARDEX sin recalcular
 * - tercero_id: proveedor (entrada) / cliente (salida)
 * - centro_costo_id: centro de costo del movimiento
 * - asiento_id: asiento contable relacionado
 * - tipo: reemplaza el enum simple por cadena con más valores
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ampliar los tipos de movimiento
        //    PostgreSQL no permite DROP COLUMN de tipo enum directamente; usamos
        //    ALTER COLUMN ... TYPE cambiando a VARCHAR y añadiendo el check.
        DB::statement(
            "ALTER TABLE inventario_movimientos
                ALTER COLUMN tipo TYPE VARCHAR(30) USING tipo::VARCHAR"
        );
        // DROP primero para ser idempotente con el hotfix 2026_05_09_150001
        // que también añade este constraint cuando lo detecta ausente.
        DB::statement('ALTER TABLE inventario_movimientos DROP CONSTRAINT IF EXISTS chk_inv_mov_tipo');
        DB::statement(
            "ALTER TABLE inventario_movimientos
                ADD CONSTRAINT chk_inv_mov_tipo CHECK (tipo IN (
                    'entrada_compra',
                    'salida_venta',
                    'traslado_salida',
                    'traslado_entrada',
                    'devolucion_compra',
                    'devolucion_venta',
                    'ajuste_positivo',
                    'ajuste_negativo',
                    'produccion_consumo',
                    'produccion_terminado'
                ))"
        );

        // Migrar valores legacy
        DB::statement("UPDATE inventario_movimientos SET tipo = 'entrada_compra' WHERE tipo = 'entrada'");
        DB::statement("UPDATE inventario_movimientos SET tipo = 'salida_venta'   WHERE tipo = 'salida'");
        DB::statement("UPDATE inventario_movimientos SET tipo = 'ajuste_positivo' WHERE tipo = 'ajuste'");

        Schema::table('inventario_movimientos', function (Blueprint $table) {
            // Bodega origen (obligatoria para nuevos movimientos)
            $table->foreignUuid('bodega_id')
                  ->nullable()           // nullable para no romper datos legacy
                  ->constrained('bodegas');

            // Bodega destino solo para traslados
            $table->foreignUuid('bodega_destino_id')
                  ->nullable()
                  ->constrained('bodegas');

            // Snapshot KARDEX (pre-calculado en cada movimiento)
            $table->decimal('saldo_unidades_despues', 15, 4)->nullable();
            $table->decimal('saldo_valor_despues',    15, 2)->nullable();
            $table->decimal('costo_promedio_despues', 15, 4)->nullable();

            // Trazabilidad
            $table->foreignUuid('tercero_id')
                  ->nullable()
                  ->constrained('terceros');
            $table->foreignUuid('centro_costo_id')
                  ->nullable()
                  ->constrained('centros_costo');
            $table->foreignUuid('asiento_id')
                  ->nullable()
                  ->constrained('asientos');
        });

        DB::statement('CREATE INDEX idx_inv_mov_bodega   ON inventario_movimientos(bodega_id)');
        DB::statement('CREATE INDEX idx_inv_mov_producto ON inventario_movimientos(producto_id)');
        DB::statement('CREATE INDEX idx_inv_mov_fecha    ON inventario_movimientos(created_at)');
    }

    public function down(): void
    {
        Schema::table('inventario_movimientos', function (Blueprint $table) {
            $table->dropColumn([
                'bodega_id', 'bodega_destino_id',
                'saldo_unidades_despues', 'saldo_valor_despues', 'costo_promedio_despues',
                'tercero_id', 'centro_costo_id', 'asiento_id',
            ]);
        });

        DB::statement('ALTER TABLE inventario_movimientos DROP CONSTRAINT IF EXISTS chk_inv_mov_tipo');
        DB::statement(
            "ALTER TABLE inventario_movimientos
                ALTER COLUMN tipo TYPE VARCHAR(30) USING tipo::VARCHAR"
        );
    }
};
