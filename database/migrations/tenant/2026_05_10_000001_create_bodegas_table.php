<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla bodegas.
 *
 * Una sucursal puede tener N bodegas. Cada bodega puede ser de un tipo
 * específico (mercancía, materia prima, producto terminado, etc.) y puede
 * sobreescribir la cuenta contable de inventario de su categoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bodegas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sucursal_id')->constrained('sucursales');

            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->enum('tipo', [
                'mercancia',
                'materia_prima',
                'producto_proceso',
                'producto_terminado',
                'consignacion',
                'devoluciones',
                'transito',
            ])->default('mercancia');

            // Cuenta contable override (prioridad sobre la de la categoría)
            $table->foreignUuid('inventario_cuenta_id')
                  ->nullable()
                  ->constrained('cuentas_contables');

            $table->foreignUuid('responsable_user_id')
                  ->nullable()
                  ->constrained('users');

            $table->boolean('es_principal')->default(false);
            $table->boolean('activa')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bodegas');
    }
};
