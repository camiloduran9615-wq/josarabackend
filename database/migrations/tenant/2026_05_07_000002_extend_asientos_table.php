<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende la tabla `asientos` con los campos exigidos por EPIC-002:
 * estado, número, periodo, origen polimórfico, actores, soportes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('asientos', function (Blueprint $table): void {
            // Identificación contable
            $table->string('numero', 30)->nullable()->after('id');
            $table->smallInteger('año_fiscal')->nullable()->after('numero');
            $table->string('tipo_comprobante', 4)->default('CG')->after('año_fiscal');
            $table->string('estado', 20)->default('borrador')->after('tipo_comprobante');
            $table->string('tipo_movimiento', 20)->default('normal')->after('estado');

            // Descripción semántica (renombramos 'glosa' → 'descripcion')
            $table->text('descripcion')->nullable()->after('numero_documento');

            // Periodo y sucursal
            $table->uuid('periodo_id')->nullable()->after('fecha');
            $table->uuid('sucursal_id')->nullable()->after('periodo_id');

            // Origen polimórfico
            $table->string('origen_type', 255)->nullable()->after('sucursal_id');
            $table->string('origen_id', 36)->nullable()->after('origen_type');

            // Reverso
            $table->uuid('origen_reverso_id')->nullable()->after('origen_id');
            $table->uuid('reversado_por_id')->nullable()->after('origen_reverso_id');

            // Actores
            $table->uuid('created_by_id')->nullable()->after('reversado_por_id');
            $table->uuid('last_modified_by_id')->nullable()->after('created_by_id');
            $table->uuid('approved_by_id')->nullable()->after('last_modified_by_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->uuid('voided_by_id')->nullable()->after('approved_at');
            $table->timestamp('voided_at')->nullable()->after('voided_by_id');
            $table->text('motivo_anulacion')->nullable()->after('voided_at');
            $table->text('motivo_reverso')->nullable()->after('motivo_anulacion');

            // Soportes documentales (Resolución DIAN 000165/2023)
            $table->json('soportes_urls')->nullable()->after('motivo_reverso');

            // Índices
            $table->index('numero');
            $table->index(['estado', 'fecha']);
            $table->index(['origen_type', 'origen_id']);
            $table->index(['tipo_comprobante', 'año_fiscal', 'numero']);
            $table->index('periodo_id');
        });

        // Idempotencia de asientos derivados:
        // un mismo documento origen no puede generar dos asientos normales.
        // Postgres soporta índices únicos parciales; SQLite no, fallback con índice normal.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS unique_asiento_origen
                ON asientos (origen_type, origen_id)
                WHERE tipo_movimiento != 'reverso' AND origen_type IS NOT NULL
            ");
        } else {
            // SQLite/MySQL: aplicar la idempotencia en el ContabilizadorService.
            // Index simple para acelerar la búsqueda del check.
            // (ya creado por el index compuesto arriba ['origen_type', 'origen_id'])
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS unique_asiento_origen');
        }

        Schema::table('asientos', function (Blueprint $table): void {
            $table->dropIndex(['estado', 'fecha']);
            $table->dropIndex(['origen_type', 'origen_id']);
            $table->dropIndex(['tipo_comprobante', 'año_fiscal', 'numero']);
            $table->dropIndex(['numero']);
            $table->dropIndex(['periodo_id']);

            $table->dropColumn([
                'numero', 'año_fiscal', 'tipo_comprobante', 'estado', 'tipo_movimiento',
                'descripcion', 'periodo_id', 'sucursal_id',
                'origen_type', 'origen_id', 'origen_reverso_id', 'reversado_por_id',
                'created_by_id', 'last_modified_by_id',
                'approved_by_id', 'approved_at',
                'voided_by_id', 'voided_at', 'motivo_anulacion', 'motivo_reverso',
                'soportes_urls',
            ]);
        });
    }
};
