<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_cartera', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tercero_id')->constrained('terceros');
            $table->foreignUuid('factura_id')->nullable()->constrained('facturas');
            $table->foreignUuid('cuenta_debito_id')->nullable()->constrained('cuentas_contables');
            $table->foreignUuid('cuenta_credito_id')->nullable()->constrained('cuentas_contables');

            $table->string('numero')->unique();
            $table->date('fecha');

            $table->enum('tipo', [
                'castigo_cartera',
                'descuento_pronto_pago',
                'provision_cartera',
                'recuperacion_cartera',
                'abono_parcial',
                'diferencia_cambio',
                'otro',
            ])->default('abono_parcial');

            $table->text('concepto');
            $table->decimal('valor', 15, 2);

            $table->enum('estado', ['borrador', 'aplicado', 'anulado'])->default('borrador');
            $table->text('observaciones')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_cartera');
    }
};
