<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remisiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tercero_id')->constrained('terceros');
            $table->foreignUuid('factura_id')->nullable()->constrained('facturas');

            $table->string('numero')->unique();
            $table->date('fecha');
            $table->date('fecha_entrega')->nullable();

            $table->string('direccion_entrega')->nullable();
            $table->string('transportista')->nullable();
            $table->string('numero_guia')->nullable();

            $table->decimal('valor_total', 15, 2)->default(0);

            $table->enum('estado', ['borrador', 'enviada', 'facturada', 'anulada'])->default('borrador');
            $table->text('observaciones')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('remision_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('remision_id')->constrained('remisiones')->onDelete('cascade');
            $table->foreignUuid('producto_id')->nullable()->constrained('productos');

            $table->string('codigo_referencia')->nullable();
            $table->string('nombre');
            $table->decimal('cantidad', 10, 2);
            $table->string('unidad_medida')->default('Unidad');
            $table->decimal('precio_unitario', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remision_items');
        Schema::dropIfExists('remisiones');
    }
};
