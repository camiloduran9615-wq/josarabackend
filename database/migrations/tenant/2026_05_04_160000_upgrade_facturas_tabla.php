<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('tipo_documento', 5)->default('FV')->after('id');
            $table->date('fecha_emision')->nullable()->after('tipo_documento');
            $table->uuid('resolucion_id')->nullable()->after('fecha_emision');
            $table->text('observaciones')->nullable()->after('valor_total');
            $table->decimal('valor_descuentos', 15, 2)->default(0)->after('valor_retenciones');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['tipo_documento', 'fecha_emision', 'resolucion_id']);
        });
    }
};
