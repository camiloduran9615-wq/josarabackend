<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodos contables del tenant.
 * Granularidad: mensual (12 por año fiscal) y anual (1 por año fiscal).
 * Estados: abierto → en_revision → cerrado → bloqueado_fiscal
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('periodos_contables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tipo', 10);            // 'mensual' | 'anual'
            $table->string('codigo', 10);          // '2026-05' | 'FY-2026'
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->smallInteger('año_fiscal');
            $table->smallInteger('mes')->nullable();
            $table->string('estado', 20)->default('abierto');

            $table->uuid('cerrado_por_id')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->text('motivo_cierre')->nullable();

            $table->uuid('reabierto_por_id')->nullable();
            $table->timestamp('reabierto_at')->nullable();
            $table->text('motivo_reapertura')->nullable();

            $table->uuid('bloqueado_fiscal_por_id')->nullable();
            $table->timestamp('bloqueado_fiscal_at')->nullable();

            $table->timestamps();

            $table->unique('codigo');
            $table->index(['estado', 'fecha_inicio']);
            $table->index(['año_fiscal', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_contables');
    }
};
