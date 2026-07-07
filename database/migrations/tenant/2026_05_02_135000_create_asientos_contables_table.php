<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Encabezado del Asiento (Libro Diario)
        Schema::create('asientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('fecha');
            $table->string('comprobante'); // Ej: Factura de Venta, Comprobante de Egreso
            $table->string('numero_documento'); // El número de la factura o soporte
            $table->text('glosa')->nullable(); // Descripción general
            
            $table->timestamps();
            $table->softDeletes();
        });

        // Detalle del Asiento (Partida Doble)
        Schema::create('asiento_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asiento_id')->constrained('asientos')->onDelete('cascade');
            $table->foreignUuid('cuenta_id')->constrained('cuentas_contables');
            $table->foreignUuid('tercero_id')->nullable()->constrained('terceros');
            
            $table->decimal('debito', 15, 2)->default(0);
            $table->decimal('credito', 15, 2)->default(0);
            $table->string('descripcion_item')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asiento_items');
        Schema::dropIfExists('asientos');
    }
};
