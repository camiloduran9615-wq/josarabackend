<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Snapshot inmutable de saldos al cerrar un periodo.
 *
 * Append-only: cualquier UPDATE/DELETE es rechazado por trigger PostgreSQL.
 * Cada fila lleva hash SHA-256 del snapshot para verificación post-hoc.
 *
 * Una fila por (cuenta_saldo_id × periodo_codigo). El periodo_codigo es 'YYYY-MM'
 * o 'YYYY-FY' (cierre mensual o anual).
 *
 * Esta tabla es la fuente legal de saldos al cerrar; cumple Resolución DIAN 000042/2020
 * y Código de Comercio art. 28 (10 años de conservación, política SaaS 15 años).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cuenta_saldos_historicos_cierre', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('cuenta_saldo_id'); // referencia al snapshot original (NO FK: cuenta_saldos puede mutar)
            $table->uuid('cuenta_contable_id');
            $table->uuid('periodo_id');
            $table->uuid('tercero_id')->nullable();
            $table->uuid('centro_costo_id')->nullable();
            $table->uuid('sucursal_id')->nullable();

            $table->decimal('saldo_inicial_debito',  18, 4);
            $table->decimal('saldo_inicial_credito', 18, 4);
            $table->decimal('movimiento_debito',     18, 4);
            $table->decimal('movimiento_credito',    18, 4);
            $table->decimal('saldo_final_debito',    18, 4);
            $table->decimal('saldo_final_credito',   18, 4);

            $table->timestampTz('cerrado_at');
            $table->uuid('cerrado_por_user_id');
            $table->char('hash_snapshot', 64);                  // SHA-256 hex
            $table->string('periodo_codigo', 10);               // '2026-05' o '2026-FY'

            $table->timestampTz('created_at')->useCurrent();
            // NO updated_at: tabla append-only

            // FKs blandos: cuenta_contable y periodo todavía válidos en BD; tercero/cc/sucursal opcionales
            $table->foreign('cuenta_contable_id')->references('id')->on('cuentas_contables')->restrictOnDelete();
            $table->foreign('periodo_id')->references('id')->on('periodos_contables')->restrictOnDelete();

            $table->unique(['cuenta_saldo_id', 'periodo_codigo'], 'uq_csh_snapshot');
            $table->index(['periodo_id'], 'idx_csh_periodo');
            $table->index(['cuenta_contable_id', 'cerrado_at'], 'idx_csh_cuenta_cerrado');
            $table->index('periodo_codigo', 'idx_csh_periodo_codigo');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Función + trigger que prohíben UPDATE y DELETE
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION cuenta_saldos_hist_no_change()
                    RETURNS TRIGGER LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'cuenta_saldos_historicos_cierre es append-only: % no permitido', TG_OP;
                END $$
            SQL);

            DB::statement(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_csh_protect ON cuenta_saldos_historicos_cierre
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_csh_protect
                    BEFORE UPDATE OR DELETE ON cuenta_saldos_historicos_cierre
                    FOR EACH ROW EXECUTE FUNCTION cuenta_saldos_hist_no_change()
            SQL);

            DB::statement("COMMENT ON TABLE cuenta_saldos_historicos_cierre IS 'Snapshot inmutable de saldos al cerrar periodo. Trigger BEFORE UPDATE/DELETE rechaza mutaciones.'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_csh_protect ON cuenta_saldos_historicos_cierre');
            DB::statement('DROP FUNCTION IF EXISTS cuenta_saldos_hist_no_change()');
        }

        Schema::dropIfExists('cuenta_saldos_historicos_cierre');
    }
};
