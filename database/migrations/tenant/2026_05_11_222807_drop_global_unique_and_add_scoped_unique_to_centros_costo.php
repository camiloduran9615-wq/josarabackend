<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cambia la unicidad de `centros_costo.codigo` de GLOBAL a CONTEXTUAL:
 *
 *  ANTES: codigo único en toda la tabla
 *         → Centro 1 + Subcentro 1 IMPOSIBLE (bloqueado)
 *
 *  DESPUÉS: codigo único dentro del mismo nivel jerárquico (mismo parent_id)
 *         → Centro  codigo=1 (parent_id=NULL)               ✅
 *         → Subcentro codigo=1 (parent_id=<centro1_id>)     ✅
 *         → Otro subcentro codigo=1 (parent_id=<centro1_id>) ❌ duplicado bajo mismo padre
 *         → Subcentro codigo=1 (parent_id=<centro2_id>)     ✅ padre diferente = válido
 *
 * PostgreSQL no considera NULL = NULL en unique constraints, por eso se usan
 * dos índices parciales separados para cubrir raíces e hijos correctamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Eliminar el constraint único global sobre codigo.
        //    En PostgreSQL es un CONSTRAINT de tabla, no un índice suelto.
        DB::statement('ALTER TABLE centros_costo DROP CONSTRAINT IF EXISTS centros_costo_codigo_unique');

        // 2a. Unicidad para centros RAÍZ (parent_id IS NULL)
        //     Impide dos centros raíz con el mismo código.
        DB::statement(
            'CREATE UNIQUE INDEX centros_costo_codigo_raiz_unique
             ON centros_costo (codigo)
             WHERE parent_id IS NULL'
        );

        // 2b. Unicidad para HIJOS (parent_id IS NOT NULL)
        //     Impide dos hermanos con el mismo código bajo el mismo padre.
        DB::statement(
            'CREATE UNIQUE INDEX centros_costo_codigo_padre_unique
             ON centros_costo (codigo, parent_id)
             WHERE parent_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS centros_costo_codigo_raiz_unique');
        DB::statement('DROP INDEX IF EXISTS centros_costo_codigo_padre_unique');

        // Restaura el constraint global original
        DB::statement(
            'ALTER TABLE centros_costo
             ADD CONSTRAINT centros_costo_codigo_unique UNIQUE (codigo)'
        );
    }
};
