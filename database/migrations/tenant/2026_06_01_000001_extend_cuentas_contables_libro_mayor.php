<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-LMB-001 — Sprint 1 Core Contable.
 *
 * Extiende `cuentas_contables` con la clasificación NIIF/PUC requerida para Libro Mayor
 * y Balances. NO duplica columnas existentes: `exige_tercero`, `exige_centro_costo` y
 * `acepta_movimientos` ya están en la tabla (canónicas) — su sincronización con las
 * variantes `requiere_*` y `tipo_cuenta` ya la maneja el boot del modelo.
 *
 * Columnas añadidas:
 *  - clase                  smallint 1-9  (clasificación PUC Decreto 2650)
 *  - clasificacion_balance  enum  corriente|no_corriente|na   (NIC 1)
 *  - clasificacion_pyg      enum  operacional|no_operacional|na  (NIC 1)
 *  - editable               bool  (false para cuentas estructurales del seed maestro)
 *  - sistema                bool  (true para cuentas que vienen pre-cargadas por el SaaS)
 *  - nif_referencia         varchar(20) nullable (mapeo a línea ESF/ERI NIIF)
 *
 * Notas operativas:
 *  - Online-safe en PostgreSQL 11+: ADD COLUMN con DEFAULT es INSTANT.
 *  - Backfill de `clase` lo hace `BackfillCuentasContablesJob` (Fase 2).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cuentas_contables')) {
            return;
        }

        Schema::table('cuentas_contables', function (Blueprint $table): void {
            if (! Schema::hasColumn('cuentas_contables', 'clase')) {
                $table->unsignedTinyInteger('clase')->nullable()->after('codigo');
            }
            if (! Schema::hasColumn('cuentas_contables', 'clasificacion_balance')) {
                $table->string('clasificacion_balance', 20)->nullable()->after('clase');
            }
            if (! Schema::hasColumn('cuentas_contables', 'clasificacion_pyg')) {
                $table->string('clasificacion_pyg', 20)->nullable()->after('clasificacion_balance');
            }
            if (! Schema::hasColumn('cuentas_contables', 'editable')) {
                $table->boolean('editable')->default(true)->after('activo');
            }
            if (! Schema::hasColumn('cuentas_contables', 'sistema')) {
                $table->boolean('sistema')->default(false)->after('editable');
            }
            if (! Schema::hasColumn('cuentas_contables', 'nif_referencia')) {
                $table->string('nif_referencia', 20)->nullable()->after('sistema');
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE cuentas_contables
                    DROP CONSTRAINT IF EXISTS chk_cc_clase,
                    ADD  CONSTRAINT chk_cc_clase
                         CHECK (clase IS NULL OR (clase BETWEEN 1 AND 9))
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE cuentas_contables
                    DROP CONSTRAINT IF EXISTS chk_cc_clas_balance,
                    ADD  CONSTRAINT chk_cc_clas_balance
                         CHECK (clasificacion_balance IS NULL OR
                                clasificacion_balance IN ('corriente','no_corriente','na'))
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE cuentas_contables
                    DROP CONSTRAINT IF EXISTS chk_cc_clas_pyg,
                    ADD  CONSTRAINT chk_cc_clas_pyg
                         CHECK (clasificacion_pyg IS NULL OR
                                clasificacion_pyg IN ('operacional','no_operacional','na'))
            SQL);
        }

        // Índices nuevos (idempotentes)
        Schema::table('cuentas_contables', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('cuentas_contables'))
                ->pluck('name')
                ->all();

            if (! in_array('idx_cc_clase', $indexes, true)) {
                $table->index('clase', 'idx_cc_clase');
            }
            if (! in_array('idx_cc_clas_balance', $indexes, true)) {
                $table->index('clasificacion_balance', 'idx_cc_clas_balance');
            }
            if (! in_array('idx_cc_clas_pyg', $indexes, true)) {
                $table->index('clasificacion_pyg', 'idx_cc_clas_pyg');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cuentas_contables')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cuentas_contables DROP CONSTRAINT IF EXISTS chk_cc_clase');
            DB::statement('ALTER TABLE cuentas_contables DROP CONSTRAINT IF EXISTS chk_cc_clas_balance');
            DB::statement('ALTER TABLE cuentas_contables DROP CONSTRAINT IF EXISTS chk_cc_clas_pyg');
        }

        Schema::table('cuentas_contables', function (Blueprint $table): void {
            $columns = [];
            foreach (['nif_referencia', 'sistema', 'editable', 'clasificacion_pyg', 'clasificacion_balance', 'clase'] as $col) {
                if (Schema::hasColumn('cuentas_contables', $col)) {
                    $columns[] = $col;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
