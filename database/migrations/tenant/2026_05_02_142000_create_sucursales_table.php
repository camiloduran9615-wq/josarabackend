<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('ciudad')->nullable();
            $table->boolean('es_principal')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        // Vincular Usuarios a Sucursales
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('sucursal_id')->nullable()->constrained('sucursales');
        });

        // Vincular Movimientos de Inventario a Sucursales
        Schema::table('inventario_movimientos', function (Blueprint $table) {
            $table->foreignUuid('sucursal_id')->nullable()->constrained('sucursales');
        });

        // Vincular Facturas a Sucursales
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignUuid('sucursal_id')->nullable()->constrained('sucursales');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) { $table->dropColumn('sucursal_id'); });
        Schema::table('inventario_movimientos', function (Blueprint $table) { $table->dropColumn('sucursal_id'); });
        Schema::table('users', function (Blueprint $table) { $table->dropColumn('sucursal_id'); });
        Schema::dropIfExists('sucursales');
    }
};
