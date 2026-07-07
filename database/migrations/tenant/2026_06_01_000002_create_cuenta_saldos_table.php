<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Tabla materializada del Libro Mayor.
 *
 * Una fila por (cuenta, periodo, tercero?, centro_costo?, sucursal?).
 * Actualizada vía `ActualizarSaldosListener` con UPSERT atómico (ON CONFLICT).
 *
 * Precisión interna `DECIMAL(18,4)`. Presentación al usuario en COP enteros.
 * Aislamiento multi-tenant: vive en la BD del tenant (stancl/tenancy 3.x).
 *
 * UNIQUE compuesto con manejo de NULLs:
 *  - PostgreSQL: índice único parcial usando COALESCE con UUID sentinel.
 *  - SQLite/MySQL: índice normal (no soporta UPSERT idéntico, fallback en aplicación).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cuenta_saldos', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('cuenta_contable_id');
            $table->uuid('periodo_id');
            $table->uuid('tercero_id')->nullable();
            $table->uuid('centro_costo_id')->nullable();
            $table->uuid('sucursal_id')->nullable();

            $table->decimal('saldo_inicial_debito',  18, 4)->default(0);
            $table->decimal('saldo_inicial_credito', 18, 4)->default(0);
            $table->decimal('movimiento_debito',     18, 4)->default(0);
            $table->decimal('movimiento_credito',    18, 4)->default(0);
            $table->decimal('saldo_final_debito',    18, 4)->default(0);
            $table->decimal('saldo_final_credito',   18, 4)->default(0);

            $table->timestampsTz();

            $table->foreign('cuenta_contable_id')->references('id')->on('cuentas_contables')->restrictOnDelete();
            $table->foreign('periodo_id')->references('id')->on('periodos_contables')->restrictOnDelete();
            $table->foreign('tercero_id')->references('id')->on('terceros')->restrictOnDelete();
            $table->foreign('centro_costo_id')->references('id')->on('centros_costo')->restrictOnDelete();
            $table->foreign('sucursal_id')->references('id')->on('sucursales')->restrictOnDelete();

            $table->index(['periodo_id', 'cuenta_contable_id'], 'idx_cs_periodo_cuenta');
            $table->index(['cuenta_contable_id', 'periodo_id'], 'idx_cs_cuenta_periodo');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE cuenta_saldos
                    ADD CONSTRAINT chk_cs_no_neg CHECK (
                        saldo_inicial_debito  >= 0 AND saldo_inicial_credito  >= 0 AND
                        movimiento_debito     >= 0 AND movimiento_credito     >= 0 AND
                        saldo_final_debito    >= 0 AND saldo_final_credito    >= 0
                    )
            SQL);

            // UNIQUE compuesto con manejo de NULLs vía UUID sentinel.
            // Esto permite que ON CONFLICT del UPSERT funcione consistentemente,
            // y bloquea duplicados aunque cualquier subset de tercero/cc/sucursal sea NULL.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX uq_cuenta_saldos
                ON cuenta_saldos (
                    cuenta_contable_id,
                    periodo_id,
                    COALESCE(tercero_id,      '00000000-0000-0000-0000-000000000000'::uuid),
                    COALESCE(centro_costo_id, '00000000-0000-0000-0000-000000000000'::uuid),
                    COALESCE(sucursal_id,     '00000000-0000-0000-0000-000000000000'::uuid)
                )
            SQL);

            DB::statement('CREATE INDEX idx_cs_tercero ON cuenta_saldos (tercero_id) WHERE tercero_id IS NOT NULL');
            DB::statement('CREATE INDEX idx_cs_cc      ON cuenta_saldos (centro_costo_id) WHERE centro_costo_id IS NOT NULL');

            // Comentarios de documentación (PostgreSQL)
            DB::statement("COMMENT ON TABLE  cuenta_saldos IS 'Libro Mayor materializado. UPSERT atómico via ActualizarSaldosListener.'");
            DB::statement("COMMENT ON COLUMN cuenta_saldos.saldo_inicial_debito  IS 'Saldo débito al iniciar el periodo. Inmutable tras cierre.'");
            DB::statement("COMMENT ON COLUMN cuenta_saldos.saldo_inicial_credito IS 'Saldo crédito al iniciar el periodo. Inmutable tras cierre.'");
            DB::statement("COMMENT ON COLUMN cuenta_saldos.movimiento_debito     IS 'Suma de débitos de asientos APROBADOS del periodo.'");
            DB::statement("COMMENT ON COLUMN cuenta_saldos.movimiento_credito    IS 'Suma de créditos de asientos APROBADOS del periodo.'");
            DB::statement("COMMENT ON COLUMN cuenta_saldos.saldo_final_debito    IS 'Recalculado: si naturaleza=débito, mostrar exceso D; sino 0.'");
            DB::statement("COMMENT ON COLUMN cuenta_saldos.saldo_final_credito   IS 'Recalculado: si naturaleza=crédito, mostrar exceso C; sino 0.'");
        } else {
            // Fallback no-PG: índice compuesto incluyendo nullables (semántica más débil; suficiente para tests)
            Schema::table('cuenta_saldos', function (Blueprint $table): void {
                $table->unique(
                    ['cuenta_contable_id', 'periodo_id', 'tercero_id', 'centro_costo_id', 'sucursal_id'],
                    'uq_cuenta_saldos'
                );
                $table->index('tercero_id', 'idx_cs_tercero');
                $table->index('centro_costo_id', 'idx_cs_cc');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cuenta_saldos');
    }
};
