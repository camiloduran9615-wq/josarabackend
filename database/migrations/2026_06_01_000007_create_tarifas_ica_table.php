<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Catálogo central de tarifas ICA por municipio × CIIU.
 *
 * Vive en BD CENTRAL: las tarifas municipales aplican a todos los tenants que operen
 * en ese municipio. Mantener una sola tabla central evita 1.100 cargas duplicadas.
 *
 * `tarifa_por_mil` DECIMAL(7,4): 9.6600 = 9.66‰ (por mil).
 * Vigencia inclusiva: tarifa aplicable cuando
 *   activa AND vigencia_desde <= fecha_operacion AND (vigencia_hasta IS NULL OR vigencia_hasta >= fecha_operacion).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tarifas_ica', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('municipio_dane', 8);
            $table->string('municipio_nombre', 100);            // denormalizado para lecturas
            $table->string('departamento_dane', 2);

            $table->string('codigo_actividad_ciiu', 10);
            $table->string('descripcion_actividad', 255);

            $table->decimal('tarifa_por_mil', 7, 4);
            $table->decimal('base_minima_uvt', 10, 2)->nullable();
            $table->decimal('base_minima_cop', 18, 2)->nullable(); // alternativa fija

            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('activa')->default(true);

            $table->string('fuente_legal', 200)->nullable();    // 'Acuerdo Bogotá 65/2002 art. 14'

            $table->timestampsTz();

            $table->unique(['municipio_dane', 'codigo_actividad_ciiu', 'vigencia_desde'], 'uq_tica_municipio_ciiu_vigencia');
            $table->index('municipio_dane', 'idx_tica_municipio');
            $table->index('codigo_actividad_ciiu', 'idx_tica_ciiu');

            // FK al catálogo DANE (existe en la migration previa de esta epic)
            $table->foreign('municipio_dane')->references('codigo_dane')->on('municipios_dane')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE tarifas_ica
                    ADD CONSTRAINT chk_tica_tarifa
                         CHECK (tarifa_por_mil >= 0 AND tarifa_por_mil <= 50),
                    ADD CONSTRAINT chk_tica_vigencia
                         CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)
            SQL);

            DB::statement("COMMENT ON TABLE tarifas_ica IS 'Catálogo central de tarifas ICA por municipio + CIIU + vigencia. Lectura compartida entre tenants.'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas_ica');
    }
};
