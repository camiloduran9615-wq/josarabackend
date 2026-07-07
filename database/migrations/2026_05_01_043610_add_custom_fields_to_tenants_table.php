<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Agrega los campos contables y empresariales a la tabla central de tenants.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('razon_social')->after('id');
            $table->string('nit', 20)->unique()->after('razon_social'); // NIT colombiano
            $table->string('email_contacto')->after('nit');
            $table->string('telefono', 20)->nullable()->after('email_contacto');
            $table->string('direccion')->nullable()->after('telefono');
            $table->string('ciudad', 100)->nullable()->after('direccion');
            $table->boolean('activo')->default(true)->after('ciudad');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'razon_social', 'nit', 'email_contacto',
                'telefono', 'direccion', 'ciudad', 'activo'
            ]);
        });
    }
};
