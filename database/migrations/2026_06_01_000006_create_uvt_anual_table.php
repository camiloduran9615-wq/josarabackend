<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Valor de la UVT por año fiscal.
 *
 * BD central. Toda la lógica tributaria (bases mínimas en UVT) consulta este catálogo
 * vía `UvtAnualRepository::vigente()`. Nunca hardcodear valores.
 *
 * Valores 2026 son PROYECCIÓN — verificar Resolución DIAN oficial antes de operación.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('uvt_anual', function (Blueprint $table): void {
            $table->smallInteger('anio')->primary();
            $table->decimal('valor_cop', 10, 2);
            $table->string('resolucion_dian', 50);
            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE uvt_anual
                    ADD CONSTRAINT chk_uvt_anio  CHECK (anio BETWEEN 2010 AND 2100),
                    ADD CONSTRAINT chk_uvt_valor CHECK (valor_cop > 0)
            SQL);

            DB::statement("COMMENT ON TABLE uvt_anual IS 'Valor UVT por año fiscal (Resolución DIAN anual). BD central, lectura compartida.'");
        }

        // Seed inmediato (referencia DIAN). El año 2026 es proyección y debe confirmarse.
        DB::table('uvt_anual')->insert([
            [
                'anio'             => 2024,
                'valor_cop'        => 47065.00,
                'resolucion_dian'  => 'Resolución DIAN 008859 de 2023',
                'vigencia_desde'   => '2024-01-01',
                'vigencia_hasta'   => '2024-12-31',
                'created_at'       => now(),
            ],
            [
                'anio'             => 2025,
                'valor_cop'        => 49799.00,
                'resolucion_dian'  => 'Resolución DIAN — pendiente referencia oficial',
                'vigencia_desde'   => '2025-01-01',
                'vigencia_hasta'   => '2025-12-31',
                'created_at'       => now(),
            ],
            [
                'anio'             => 2026,
                'valor_cop'        => 49799.00, // PROYECCIÓN — ajustar al confirmar resolución
                'resolucion_dian'  => 'Resolución DIAN — pendiente publicación',
                'vigencia_desde'   => '2026-01-01',
                'vigencia_hasta'   => null,
                'created_at'       => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('uvt_anual');
    }
};
