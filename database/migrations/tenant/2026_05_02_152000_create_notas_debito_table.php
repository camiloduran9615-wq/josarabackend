<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_debito', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tercero_id')->constrained('terceros');
            $table->foreignUuid('factura_id')->nullable()->constrained('facturas');

            $table->string('numero')->unique();
            $table->date('fecha');

            $table->string('concepto_codigo')->default('01'); // 01=Intereses, 02=Gastos, 03=Cambio de valor, etc.
            $table->text('descripcion');

            $table->decimal('valor_bruto', 15, 2)->default(0);
            $table->decimal('valor_iva', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2)->default(0);

            $table->string('cufe')->nullable();
            $table->text('public_url')->nullable();

            $table->enum('estado', ['borrador', 'validado', 'error', 'anulado'])->default('borrador');
            $table->json('errores_api')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nota_debito_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('nota_debito_id')->constrained('notas_debito')->onDelete('cascade');

            $table->string('nombre');
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('precio_unitario', 15, 2);
            $table->decimal('porcentaje_iva', 5, 2)->default(0);
            $table->decimal('valor_iva', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_debito_items');
        Schema::dropIfExists('notas_debito');
    }
};
