<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Cuenta de Inventario (Activo - Ej: 1435)
            $table->foreignUuid('inventario_cuenta_id')->nullable()->constrained('cuentas_contables');
            
            // Cuenta de Ventas (Ingresos - Ej: 4135)
            $table->foreignUuid('ventas_cuenta_id')->nullable()->constrained('cuentas_contables');
            
            // Cuenta de Costos (Costos de Venta - Ej: 6135)
            $table->foreignUuid('costos_cuenta_id')->nullable()->constrained('cuentas_contables');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['inventario_cuenta_id', 'ventas_cuenta_id', 'costos_cuenta_id']);
        });
    }
};
