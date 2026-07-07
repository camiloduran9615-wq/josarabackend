<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega código CIIU (Clasificación Industrial Internacional Uniforme) a la tabla terceros.
 *
 * Obligatorio para:
 *   - Facturación Electrónica DIAN UBL 2.1 (CustomerParty/IndustryClassificationCode)
 *   - Información Exógena DIAN Formato 1001 (pagos a terceros)
 *   - Cálculo correcto de ReteICA por actividad económica (Acuerdo municipal)
 *
 * Resolución DIAN 000139/2012 — Catálogo CIIU Rev. 4 A.C. (4 dígitos)
 * Ejemplos: 4690 Comercio al por mayor · 4711 Comercio minorista · 7110 Arquitectura
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('terceros', function (Blueprint $table): void {
            // CIIU Rev. 4 — siempre 4 dígitos. Nullable para no romper terceros existentes.
            $table->string('codigo_ciiu', 4)->nullable()->after('responsabilidades_fiscales');
            $table->index('codigo_ciiu', 'idx_terceros_ciiu');
        });
    }

    public function down(): void
    {
        Schema::table('terceros', function (Blueprint $table): void {
            $table->dropIndex('idx_terceros_ciiu');
            $table->dropColumn('codigo_ciiu');
        });
    }
};
