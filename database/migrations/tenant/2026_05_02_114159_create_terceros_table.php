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
        Schema::create('terceros', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Identificación (DIAN/Factus)
            $table->string('identificacion_documento_id', 5); // identification_document_code (Ej: 13, 31)
            $table->string('identificacion', 20)->unique();
            $table->string('dv', 1)->nullable(); // Dígito de verificación
            
            // Organización y Tributos (DIAN/Factus)
            $table->string('organizacion_juridica_id', 2); // legal_organization_code (1: Jurídica, 2: Natural)
            $table->string('tributo_id', 5)->default('ZZ'); // tribute_code (Ej: 01 - IVA, ZZ - No aplica)
            
            // Nombres y Razón Social
            $table->string('razon_social'); // company / names
            $table->string('nombre_comercial')->nullable(); // trade_name
            
            // Contacto
            $table->string('direccion');
            $table->string('email');
            $table->string('telefono', 20)->nullable();
            
            // Ubicación (DIAN/Factus)
            $table->string('municipio_id', 10); // municipality_code (Código DANE)
            
            // Banderas (Rol en el negocio)
            $table->boolean('es_cliente')->default(true);
            $table->boolean('es_proveedor')->default(false);
            $table->boolean('es_empleado')->default(false);
            
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terceros');
    }
};
