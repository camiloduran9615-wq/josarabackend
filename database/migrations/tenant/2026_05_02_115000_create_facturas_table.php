<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->foreignUuid('tercero_id')->constrained('terceros');
            
            // Datos de la resolución
            $table->integer('numbering_range_id'); // ID en Factus
            $table->string('prefijo')->nullable();
            $table->string('numero')->nullable(); // Ej: 1001
            $table->string('numero_completo')->nullable(); // Ej: SETP1001
            
            // Valores
            $table->decimal('valor_bruto', 15, 2);
            $table->decimal('valor_impuestos', 15, 2);
            $table->decimal('valor_retenciones', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2);
            
            // Factus Data
            $table->string('reference_code')->unique(); // Nuestro código interno (Ej: FACT-001)
            $table->string('cufe')->nullable();
            $table->text('qr_url')->nullable();
            $table->text('public_url')->nullable(); // PDF
            
            $table->enum('estado', ['borrador', 'validado', 'error', 'anulado'])->default('borrador');
            $table->json('errores_api')->nullable();
            
            $table->timestamp('fecha_validacion')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('factura_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('factura_id')->constrained('facturas')->onDelete('cascade');
            
            $table->string('codigo_referencia');
            $table->string('nombre');
            $table->decimal('cantidad', 10, 2);
            $table->decimal('precio_unitario', 15, 2);
            $table->decimal('porcentaje_descuento', 5, 2)->default(0);
            
            $table->decimal('porcentaje_iva', 5, 2)->default(0);
            $table->decimal('valor_iva', 15, 2);
            
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_items');
        Schema::dropIfExists('facturas');
    }
};
