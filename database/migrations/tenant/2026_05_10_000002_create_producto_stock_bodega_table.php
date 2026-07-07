<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla pivote que almacena el stock de cada producto POR bodega.
 *
 * - saldo_unidades: cantidad física disponible
 * - saldo_valor:    valor monetario total al Costo Promedio Ponderado (CPP)
 * - costo_promedio: saldo_valor / saldo_unidades — el CPP vigente
 * - version:        columna para locking optimista (incrementada en cada update)
 *
 * Esta tabla es la fuente de verdad del inventario; productos.stock_actual
 * queda como campo de conveniencia (suma de todas las bodegas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_stock_bodega', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('producto_id')
                  ->constrained('productos')
                  ->cascadeOnDelete();
            $table->foreignUuid('bodega_id')
                  ->constrained('bodegas')
                  ->cascadeOnDelete();

            $table->decimal('saldo_unidades', 15, 4)->default(0);
            $table->decimal('saldo_valor',    15, 2)->default(0);
            $table->decimal('costo_promedio', 15, 4)->default(0);

            $table->timestamp('ultima_entrada_at')->nullable();
            $table->timestamp('ultima_salida_at')->nullable();

            // Locking optimista: se incrementa en cada UPDATE del stock
            $table->unsignedInteger('version')->default(0);

            $table->timestamps();

            $table->unique(['producto_id', 'bodega_id']);
        });

        // Índices adicionales para reportes (KARDEX / valorización)
        DB::statement('CREATE INDEX idx_psb_bodega   ON producto_stock_bodega(bodega_id)');
        DB::statement('CREATE INDEX idx_psb_producto ON producto_stock_bodega(producto_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_stock_bodega');
    }
};
