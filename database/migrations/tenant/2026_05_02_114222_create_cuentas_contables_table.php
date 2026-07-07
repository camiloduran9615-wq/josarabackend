<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cuentas_contables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->string('codigo', 20)->unique(); // Ej: 110505
            $table->string('nombre', 255);
            $table->enum('naturaleza', ['debito', 'credito']);
            $table->enum('nivel', ['clase', 'grupo', 'cuenta', 'subcuenta', 'auxiliar']);
            
            // Relación recursiva opcional para jerarquías (opcional, ya que el código es jerárquico implícitamente)
            $table->uuid('parent_id')->nullable();
            
            // Banderas contables
            $table->boolean('acepta_movimientos')->default(false); // Solo las auxiliares deberían aceptar
            $table->boolean('exige_tercero')->default(false);
            $table->boolean('exige_centro_costo')->default(false);
            $table->boolean('exige_base_impuesto')->default(false); // Para retenciones, IVA
            
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Agregamos la llave foránea recursiva después de que la tabla ya ha sido creada
        Schema::table('cuentas_contables', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('cuentas_contables')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuentas_contables');
    }
};
