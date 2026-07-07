<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_ingreso', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tercero_id')->constrained('terceros');

            $table->string('numero')->unique();
            $table->enum('tipo', ['factura_compra', 'cuenta_cobro', 'gasto', 'otro'])->default('factura_compra');
            $table->date('fecha');
            $table->date('fecha_vencimiento')->nullable();

            $table->text('concepto');
            $table->enum('forma_pago', ['contado', 'credito'])->default('contado');

            $table->decimal('valor_bruto', 15, 2)->default(0);
            $table->decimal('valor_iva', 15, 2)->default(0);
            $table->decimal('valor_retefuente', 15, 2)->default(0);
            $table->decimal('valor_reteica', 15, 2)->default(0);
            $table->decimal('valor_reteiva', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2)->default(0);

            $table->enum('estado', ['borrador', 'registrado', 'anulado'])->default('borrador');
            $table->text('observaciones')->nullable();
            $table->string('numero_documento_proveedor')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('documento_ingreso_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('documento_ingreso_id')->constrained('documentos_ingreso')->onDelete('cascade');
            $table->foreignUuid('cuenta_id')->nullable()->constrained('cuentas_contables');

            $table->string('descripcion');
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
        Schema::dropIfExists('documento_ingreso_items');
        Schema::dropIfExists('documentos_ingreso');
    }
};
