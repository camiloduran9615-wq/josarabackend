<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documentos_ingreso DROP CONSTRAINT IF EXISTS documentos_ingreso_forma_pago_check');
        DB::statement(<<<'SQL'
            ALTER TABLE documentos_ingreso
            ADD CONSTRAINT documentos_ingreso_forma_pago_check
            CHECK (forma_pago IN ('contado', 'contado_efectivo', 'contado_banco', 'credito'))
        SQL);
    }

    public function down(): void
    {
        DB::table('documentos_ingreso')
            ->whereIn('forma_pago', ['contado_efectivo', 'contado_banco'])
            ->update(['forma_pago' => 'contado']);

        DB::statement('ALTER TABLE documentos_ingreso DROP CONSTRAINT IF EXISTS documentos_ingreso_forma_pago_check');
        DB::statement(<<<'SQL'
            ALTER TABLE documentos_ingreso
            ADD CONSTRAINT documentos_ingreso_forma_pago_check
            CHECK (forma_pago IN ('contado', 'credito'))
        SQL);
    }
};
