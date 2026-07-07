<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Índice PARCIAL: solo aplica cuando numero_documento_proveedor no es NULL
        // y el registro no está soft-deleted. Permite re-registrar el mismo número
        // si el documento original fue anulado (deleted_at IS NOT NULL).
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS documentos_ingreso_proveedor_doc_unique
            ON documentos_ingreso (tercero_id, numero_documento_proveedor)
            WHERE numero_documento_proveedor IS NOT NULL
              AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documentos_ingreso_proveedor_doc_unique');
    }
};
