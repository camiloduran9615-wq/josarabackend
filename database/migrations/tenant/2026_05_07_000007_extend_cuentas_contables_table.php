<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega flags de comportamiento a `cuentas_contables`:
 *  - requiere_tercero: la cuenta exige tercero asociado en cada línea
 *  - tipo_cuenta: agrupacion (no recibe movimientos) | movimiento
 *  - requiere_centro_costo: la cuenta exige centro de costo
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cuentas_contables')) {
            return;
        }

        Schema::table('cuentas_contables', function (Blueprint $table): void {
            if (! Schema::hasColumn('cuentas_contables', 'requiere_tercero')) {
                $table->boolean('requiere_tercero')->default(false);
            }
            if (! Schema::hasColumn('cuentas_contables', 'tipo_cuenta')) {
                $table->string('tipo_cuenta', 20)->default('movimiento');
            }
            if (! Schema::hasColumn('cuentas_contables', 'requiere_centro_costo')) {
                $table->boolean('requiere_centro_costo')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cuentas_contables')) {
            return;
        }

        Schema::table('cuentas_contables', function (Blueprint $table): void {
            $cols = [];
            if (Schema::hasColumn('cuentas_contables', 'requiere_tercero')) {
                $cols[] = 'requiere_tercero';
            }
            if (Schema::hasColumn('cuentas_contables', 'tipo_cuenta')) {
                $cols[] = 'tipo_cuenta';
            }
            if (Schema::hasColumn('cuentas_contables', 'requiere_centro_costo')) {
                $cols[] = 'requiere_centro_costo';
            }
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
