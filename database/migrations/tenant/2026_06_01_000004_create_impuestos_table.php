<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Catálogo parametrizable de impuestos.
 *
 * Tipo: iva | retefuente | reteiva | reteica | autorretencion
 * - tarifa_porcentaje DECIMAL(7,4): 19.0000 = 19%, 0.1100 = 0.11%
 * - base_minima_uvt   DECIMAL(10,2) nullable: 4.00, 27.00, NULL si sin base
 * - cuenta_contable_id: a qué cuenta del PUC se contabiliza
 * - sistema=true para tarifas DIAN nacionales pre-cargadas (no editables por el tenant)
 *
 * Vigencias inclusivas: una tarifa vigente cuando
 *   activa AND vigencia_desde <= hoy AND (vigencia_hasta IS NULL OR vigencia_hasta >= hoy)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('impuestos', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('tipo', 20);                          // enum lógico (CHECK PG)
            $table->string('codigo', 30);                        // 'IVA_19', 'RF_HONORARIOS_PJ', etc.
            $table->string('codigo_dian_ubl', 10)->nullable();   // UBL 2.1
            $table->string('concepto_dian', 10)->nullable();     // '027' honorarios, '022' servicios
            $table->string('nombre', 150);

            $table->decimal('tarifa_porcentaje', 7, 4);
            $table->decimal('base_minima_uvt', 10, 2)->nullable();

            $table->boolean('aplica_compras')->default(false);
            $table->boolean('aplica_ventas')->default(false);

            $table->uuid('cuenta_contable_id');
            $table->uuid('cuenta_contrapartida_id')->nullable();

            $table->string('actividad_ciiu', 10)->nullable();

            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('activa')->default(true);

            $table->text('descripcion')->nullable();
            $table->json('metadata')->nullable();

            $table->boolean('sistema')->default(false);
            $table->uuid('created_by_user_id')->nullable();

            $table->timestampsTz();

            $table->foreign('cuenta_contable_id')->references('id')->on('cuentas_contables')->restrictOnDelete();
            $table->foreign('cuenta_contrapartida_id')->references('id')->on('cuentas_contables')->restrictOnDelete();

            $table->unique(['codigo', 'vigencia_desde'], 'uq_imp_codigo_vigencia');
            $table->index(['tipo', 'vigencia_desde', 'vigencia_hasta'], 'idx_imp_tipo_vigencia');
            $table->index('actividad_ciiu', 'idx_imp_ciiu');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE impuestos
                    ADD CONSTRAINT chk_imp_tipo
                        CHECK (tipo IN ('iva','retefuente','reteiva','reteica','autorretencion')),
                    ADD CONSTRAINT chk_imp_aplica
                        CHECK (aplica_compras OR aplica_ventas),
                    ADD CONSTRAINT chk_imp_tarifa
                        CHECK (tarifa_porcentaje >= 0 AND tarifa_porcentaje <= 100),
                    ADD CONSTRAINT chk_imp_vigencia
                        CHECK (vigencia_hasta IS NULL OR vigencia_hasta >= vigencia_desde)
            SQL);

            DB::statement("COMMENT ON TABLE impuestos IS 'Catálogo parametrizable de impuestos por tenant. sistema=true para tarifas DIAN preinstaladas.'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impuestos');
    }
};
