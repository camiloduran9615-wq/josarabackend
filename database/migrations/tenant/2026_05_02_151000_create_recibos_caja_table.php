<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recibos_caja', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tercero_id')->constrained('terceros');

            $table->string('numero')->unique();
            $table->date('fecha');

            $table->decimal('valor_recibido', 15, 2);
            $table->text('concepto');

            $table->enum('forma_pago', [
                'efectivo', 'cheque', 'transferencia', 'tarjeta_debito',
                'tarjeta_credito', 'consignacion', 'otro'
            ])->default('efectivo');

            $table->string('banco')->nullable();
            $table->string('numero_cheque')->nullable();
            $table->string('referencia_pago')->nullable();

            $table->json('facturas_aplicadas')->nullable();

            $table->enum('estado', ['borrador', 'registrado', 'anulado'])->default('borrador');
            $table->text('observaciones')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos_caja');
    }
};
