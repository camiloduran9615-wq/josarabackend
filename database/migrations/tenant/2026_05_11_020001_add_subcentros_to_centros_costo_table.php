<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte de jerarquía (subcentros) a la tabla centros_costo.
 * Máximo 3 niveles: Centro → Subcentro → Sub-subcentro.
 *
 * - parent_id : FK self-referencial (null = raíz)
 * - nivel     : 1 raíz | 2 hijo | 3 nieto — calculado al crear
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centros_costo', function (Blueprint $table) {
            // FK self-referencial
            $table->foreignUuid('parent_id')
                  ->nullable()
                  ->constrained('centros_costo')
                  ->nullOnDelete()
                  ->after('id');

            // Nivel calculado (1 = raíz, 2 = hijo, 3 = nieto)
            $table->unsignedTinyInteger('nivel')
                  ->default(1)
                  ->after('activo');

            // Índice para consultas rápidas de árbol
            $table->index('parent_id');
            $table->index('nivel');
        });
    }

    public function down(): void
    {
        Schema::table('centros_costo', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['nivel']);
            $table->dropColumn(['parent_id', 'nivel']);
        });
    }
};
