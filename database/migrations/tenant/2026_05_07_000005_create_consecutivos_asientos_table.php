<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de control de consecutivos por (tipo_comprobante, año_fiscal).
 * Usa SELECT FOR UPDATE para garantizar consecutividad sin saltos
 * (Resolución DIAN 000042/2020 art. 11, art. 56 Código de Comercio).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('consecutivos_asientos', function (Blueprint $table): void {
            $table->string('tipo_comprobante', 4);
            $table->smallInteger('año_fiscal');
            $table->bigInteger('ultimo_consecutivo')->default(0);
            $table->timestamps();

            $table->primary(['tipo_comprobante', 'año_fiscal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consecutivos_asientos');
    }
};
