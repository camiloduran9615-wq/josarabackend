<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centros de costo para reportes por área/departamento.
 * Requerido en facturas de compra (igual que SIIGO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros_costo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->foreignUuid('sucursal_id')
                  ->nullable()
                  ->constrained('sucursales');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centros_costo');
    }
};
