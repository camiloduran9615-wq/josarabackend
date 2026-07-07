<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rellenar nulos antes de poder hacer nullable (por filas existentes)
        DB::statement("UPDATE terceros SET organizacion_juridica_id = '2' WHERE organizacion_juridica_id IS NULL OR organizacion_juridica_id = ''");
        DB::statement("UPDATE terceros SET direccion = '' WHERE direccion IS NULL");
        DB::statement("UPDATE terceros SET email = '' WHERE email IS NULL");
        DB::statement("UPDATE terceros SET municipio_id = '' WHERE municipio_id IS NULL");

        Schema::table('terceros', function (Blueprint $table) {
            $table->string('organizacion_juridica_id', 2)->nullable()->default(null)->change();
            $table->string('direccion')->nullable()->default(null)->change();
            $table->string('email')->nullable()->default(null)->change();
            $table->string('municipio_id', 10)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('terceros', function (Blueprint $table) {
            $table->string('organizacion_juridica_id', 2)->nullable(false)->change();
            $table->string('direccion')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('municipio_id', 10)->nullable(false)->change();
        });
    }
};
