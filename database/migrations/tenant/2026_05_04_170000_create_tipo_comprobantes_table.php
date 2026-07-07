<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_comprobantes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identificación del comprobante
            $table->string('codigo', 10);          // FV-1, FV-2, DC-1
            $table->string('nombre', 100);         // "Factura de Venta Principal"
            $table->string('tipo_documento', 5)->default('FV'); // FV, DC, NC, ND

            // Resolución DIAN vinculada
            $table->uuid('resolucion_id')->nullable();

            // Configuración de numeración
            $table->integer('consecutivo_actual')->default(1);

            // Configuración de comportamiento
            $table->string('prefijo_override', 20)->nullable(); // Override al prefijo de la resolución
            $table->text('observaciones_default')->nullable();  // Nota impresa en el documento

            // Cuentas contables por defecto (opcionales)
            $table->uuid('cuenta_ventas_id')->nullable();
            $table->uuid('cuenta_clientes_id')->nullable();
            $table->uuid('cuenta_iva_id')->nullable();

            // Vendedor por defecto
            $table->uuid('vendedor_id')->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_comprobantes');
    }
};
