<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Catálogo central de municipios DANE.
 *
 * Vive en BD CENTRAL: los códigos DANE son nacionales, idénticos para todos los tenants.
 * Permite resolver el `municipio_dane` capturado en `terceros` y servir el catálogo
 * para selects en frontend (autocomplete con búsqueda por nombre — índice GIN trigram).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('municipios_dane', function (Blueprint $table): void {
            $table->string('codigo_dane', 8)->primary();
            $table->string('municipio_nombre', 100);
            $table->string('departamento_dane', 2);
            $table->string('departamento_nombre', 100);
            $table->string('region', 50)->nullable();
            $table->boolean('activo')->default(true);

            $table->index('departamento_dane', 'idx_mun_depto');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Trigram para autocomplete por nombre de municipio
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX idx_mun_nombre_trgm ON municipios_dane USING gin (municipio_nombre gin_trgm_ops)');

            DB::statement("COMMENT ON TABLE municipios_dane IS 'Catálogo nacional DANE. BD central, compartida entre tenants.'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_mun_nombre_trgm');
        }
        Schema::dropIfExists('municipios_dane');
    }
};
