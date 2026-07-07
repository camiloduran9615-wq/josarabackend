<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_credito', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('factura_id')->constrained('facturas');
            
            // Datos de la nota
            $table->string('numero')->nullable();
            $table->string('numero_completo')->nullable();
            
            // Valores
            $table->decimal('valor_total', 15, 2);
            
            // Factus Data
            $table->string('reference_code')->unique();
            $table->string('cufe')->nullable();
            $table->text('public_url')->nullable();
            
            $table->string('discrepancy_response_code'); // Concepto de la nota (Ej: 2 - Anulación)
            $table->text('discrepancy_response_description')->nullable();
            
            $table->enum('estado', ['validado', 'error'])->default('validado');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_credito');
    }
};
