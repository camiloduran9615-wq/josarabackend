<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hotfix: La migración 2026_05_10_000004 añadió el constraint chk_inv_mov_tipo
 * pero NO eliminó el constraint original generado por Laravel al crear el enum:
 * "inventario_movimientos_tipo_check" → solo permite ('entrada','salida','ajuste').
 *
 * Esto causaba SQLSTATE[23514] al insertar 'entrada_compra'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Eliminar constraint legado con los valores del enum original
        DB::statement(
            'ALTER TABLE inventario_movimientos
             DROP CONSTRAINT IF EXISTS inventario_movimientos_tipo_check'
        );

        // El constraint correcto (chk_inv_mov_tipo) ya existe desde la migración anterior.
        // Solo validamos que esté presente; si no, lo re-creamos.
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.check_constraints
            WHERE constraint_schema = current_schema()
              AND constraint_name = 'chk_inv_mov_tipo'
        ");

        if (! $exists) {
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
        }
    }

    public function down(): void
    {
        // Restaurar constraint original (solo útil si se revierte a datos legacy)
        DB::statement(
            "ALTER TABLE inventario_movimientos
             ADD CONSTRAINT inventario_movimientos_tipo_check
             CHECK (tipo IN ('entrada','salida','ajuste'))"
        );
    }
};
