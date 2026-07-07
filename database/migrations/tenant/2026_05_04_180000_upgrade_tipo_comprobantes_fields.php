<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipo_comprobantes', function (Blueprint $table) {
            $table->boolean('habilitar_rete_iva')->default(false)->after('observaciones_default');
            $table->boolean('habilitar_rete_ica')->default(false)->after('habilitar_rete_iva');
            $table->boolean('habilitar_autorretencion')->default(false)->after('habilitar_rete_ica');
            $table->string('titulo_pdf', 100)->nullable()->after('habilitar_autorretencion');
        });
    }

    public function down(): void
    {
        Schema::table('tipo_comprobantes', function (Blueprint $table) {
            $table->dropColumn(['habilitar_rete_iva', 'habilitar_rete_ica', 'habilitar_autorretencion', 'titulo_pdf']);
        });
    }
};
