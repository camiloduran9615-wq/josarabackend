<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asocia cada DocumentoIngreso con su TipoDocumentoIngreso parametrizable.
 * Este FK es nullable — documentos sin tipo usan la parametrización contable global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_ingreso', function (Blueprint $table) {
            $table->foreignUuid('tipo_documento_ingreso_id')
                  ->nullable()
                  ->constrained('tipos_documento_ingreso')
                  ->nullOnDelete()
                  ->after('sucursal_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_ingreso', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_documento_ingreso_id');
        });
    }
};
