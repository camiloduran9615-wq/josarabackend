<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceros', function (Blueprint $table) {
            // Datos Básicos Pro
            $table->string('tipo_persona')->default('Persona Natural'); // Persona Natural / Jurídica
            $table->string('sucursal')->default('0');
            $table->string('nombres')->nullable();
            $table->string('apellidos')->nullable();
            
            // Datos de Facturación y Envío (Estándar Siigo)
            $table->string('regimen_iva')->nullable(); // Común, Simplificado, No responsable
            $table->json('responsabilidades_fiscales')->nullable(); // Array de códigos: O-13, O-23, etc.
            $table->string('codigo_postal', 10)->nullable();
            $table->string('nombre_contacto')->nullable();
            
            // Logística y Ventas
            $table->string('vendedor_id')->nullable();
            $table->string('cobrador_id')->nullable();
            $table->text('observaciones')->nullable();
            
            // Soporte para múltiples contactos (JSON para flexibilidad inicial)
            $table->json('contactos_adicionales')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terceros', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_persona', 'sucursal', 'nombres', 'apellidos', 
                'regimen_iva', 'responsabilidades_fiscales', 'codigo_postal', 
                'nombre_contacto', 'vendedor_id', 'cobrador_id', 
                'observaciones', 'contactos_adicionales'
            ]);
        });
    }
};
