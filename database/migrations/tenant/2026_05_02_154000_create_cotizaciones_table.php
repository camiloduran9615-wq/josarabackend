<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tercero_id')->constrained('terceros');

            $table->string('numero')->unique();
            $table->date('fecha');
            $table->date('fecha_validez');

            $table->text('condiciones_comerciales')->nullable();
            $table->text('observaciones')->nullable();

            $table->decimal('valor_bruto', 15, 2)->default(0);
            $table->decimal('valor_descuento', 15, 2)->default(0);
            $table->decimal('valor_iva', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2)->default(0);

            $table->enum('estado', ['borrador', 'enviada', 'aceptada', 'rechazada', 'vencida', 'facturada'])
                ->default('borrador');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cotizacion_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cotizacion_id')->constrained('cotizaciones')->onDelete('cascade');
            $table->foreignUuid('producto_id')->nullable()->constrained('productos');

            $table->string('codigo_referencia')->nullable();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('cantidad', 10, 2);
            $table->string('unidad_medida')->default('Unidad');
            $table->decimal('precio_unitario', 15, 2);
            $table->decimal('porcentaje_descuento', 5, 2)->default(0);
            $table->decimal('porcentaje_iva', 5, 2)->default(0);
            $table->decimal('valor_iva', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');
    }
};
