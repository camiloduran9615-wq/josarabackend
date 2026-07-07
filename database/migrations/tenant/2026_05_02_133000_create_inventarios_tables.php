<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categorías de productos
        Schema::create('categorias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->timestamps();
        });

        // Catálogo de Productos
        Schema::create('productos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('unidad_medida')->default('94'); // Estándar Factus (Unidad)
            
            $table->decimal('precio_venta', 15, 2)->default(0);
            $table->decimal('precio_compra', 15, 2)->default(0);
            
            $table->decimal('stock_actual', 15, 2)->default(0);
            $table->decimal('stock_minimo', 15, 2)->default(0);
            
            $table->foreignUuid('categoria_id')->nullable()->constrained('categorias');
            $table->decimal('porcentaje_iva', 5, 2)->default(19);
            
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Historial de Movimientos (Entradas/Salidas)
        Schema::create('inventario_movimientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('producto_id')->constrained('productos')->onDelete('cascade');
            
            $table->enum('tipo', ['entrada', 'salida', 'ajuste']);
            $table->decimal('cantidad', 15, 2);
            $table->decimal('precio_unitario', 15, 2);
            $table->string('concepto'); // Ej: Compra, Venta, Ajuste por daño
            
            $table->foreignUuid('factura_id')->nullable()->constrained('facturas');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_movimientos');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias');
    }
};
