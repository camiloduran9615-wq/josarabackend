<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación Bancaria.
 *
 * extractos_bancarios → un archivo subido por cuenta / período
 * lineas_extracto     → cada movimiento del extracto (débito/crédito banco)
 * conciliaciones      → match entre una línea del extracto y un recibo de caja / egreso
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extractos_bancarios', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('banco', 80);
            $table->string('numero_cuenta', 30);
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->decimal('saldo_inicial', 18, 4)->default(0);
            $table->decimal('saldo_final', 18, 4)->default(0);
            $table->string('archivo_nombre', 200)->nullable();
            $table->string('estado', 20)->default('importado'); // importado, conciliado, cerrado
            $table->uuid('importado_por_id')->nullable();
            $table->timestamps();
        });

        Schema::create('lineas_extracto', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('extracto_id');
            $table->foreign('extracto_id')->references('id')->on('extractos_bancarios')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('descripcion', 300)->nullable();
            $table->string('referencia', 100)->nullable();
            $table->decimal('debito', 18, 4)->default(0);   // dinero que salió del banco
            $table->decimal('credito', 18, 4)->default(0);  // dinero que entró al banco
            $table->decimal('saldo', 18, 4)->default(0);
            $table->string('tipo', 20)->nullable();          // transferencia, cheque, consignacion, cargo, pse
            $table->string('estado_conciliacion', 20)->default('pendiente'); // pendiente, conciliado, en_revision
            $table->timestamps();
        });

        Schema::create('conciliaciones', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('linea_extracto_id');
            $table->foreign('linea_extracto_id')->references('id')->on('lineas_extracto');
            // El movimiento del sistema que se concilia (puede ser recibo de caja o comprobante egreso)
            $table->string('origen_type', 100)->nullable();  // ReciboCaja, ComprobanteEgreso, AsientoLinea
            $table->uuid('origen_id')->nullable();
            $table->string('tipo_conciliacion', 20)->default('automatica'); // automatica, manual
            $table->decimal('diferencia', 18, 4)->default(0);
            $table->text('nota')->nullable();
            $table->uuid('conciliado_por_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliaciones');
        Schema::dropIfExists('lineas_extracto');
        Schema::dropIfExists('extractos_bancarios');
    }
};
