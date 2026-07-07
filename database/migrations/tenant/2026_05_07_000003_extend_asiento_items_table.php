<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía precisión de débito/crédito a DECIMAL(18,4) y agrega
 * centro_costo_id, documento_referencia y CHECK constraint
 * para que cada línea sea exclusivamente débito o crédito.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('asiento_items', function (Blueprint $table): void {
            $table->uuid('centro_costo_id')->nullable()->after('tercero_id');
            $table->string('documento_referencia', 50)->nullable()->after('descripcion_item');
        });

        // Postgres: alter column type. SQLite no soporta alter de tipo
        // (los modelos limitarán precisión por casts). MySQL sí soporta.
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE asiento_items ALTER COLUMN debito TYPE DECIMAL(18,4) USING debito::DECIMAL(18,4)');
            DB::statement('ALTER TABLE asiento_items ALTER COLUMN credito TYPE DECIMAL(18,4) USING credito::DECIMAL(18,4)');

            DB::statement('
                ALTER TABLE asiento_items
                ADD CONSTRAINT chk_debito_xor_credito
                CHECK ((debito > 0 AND credito = 0) OR (debito = 0 AND credito > 0))
            ');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE asiento_items MODIFY debito DECIMAL(18,4) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE asiento_items MODIFY credito DECIMAL(18,4) NOT NULL DEFAULT 0');
        }
        // SQLite: tipo se mantiene como decimal(15,2); validación XOR vive en FormRequest.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE asiento_items DROP CONSTRAINT IF EXISTS chk_debito_xor_credito');
            DB::statement('ALTER TABLE asiento_items ALTER COLUMN debito TYPE DECIMAL(15,2) USING debito::DECIMAL(15,2)');
            DB::statement('ALTER TABLE asiento_items ALTER COLUMN credito TYPE DECIMAL(15,2) USING credito::DECIMAL(15,2)');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE asiento_items MODIFY debito DECIMAL(15,2) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE asiento_items MODIFY credito DECIMAL(15,2) NOT NULL DEFAULT 0');
        }

        Schema::table('asiento_items', function (Blueprint $table): void {
            $table->dropColumn(['centro_costo_id', 'documento_referencia']);
        });
    }
};
