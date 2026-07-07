<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceros', function (Blueprint $table) {
            if (!Schema::hasColumn('terceros', 'identificacion_documento_id')) {
                $table->string('identificacion_documento_id', 5)->nullable();
            }
            if (!Schema::hasColumn('terceros', 'identificacion')) {
                $table->string('identificacion', 20)->nullable();
            }
            if (!Schema::hasColumn('terceros', 'dv')) {
                $table->string('dv', 1)->nullable();
            }
            if (!Schema::hasColumn('terceros', 'organizacion_juridica_id')) {
                $table->string('organizacion_juridica_id', 2)->nullable();
            }
            if (!Schema::hasColumn('terceros', 'tributo_id')) {
                $table->string('tributo_id', 5)->default('ZZ');
            }
            if (!Schema::hasColumn('terceros', 'razon_social')) {
                $table->string('razon_social')->nullable();
            }
            if (!Schema::hasColumn('terceros', 'nombre_comercial')) {
                $table->string('nombre_comercial')->nullable();
            }
            if (!Schema::hasColumn('terceros', 'direccion')) {
                $table->string('direccion')->nullable();
            }
            if (!Schema::hasColumn('terceros', 'email')) {
                $table->string('email')->nullable();
            }
            if (!Schema::hasColumn('terceros', 'telefono')) {
                $table->string('telefono', 20)->nullable();
            }
            if (!Schema::hasColumn('terceros', 'municipio_id')) {
                $table->string('municipio_id', 10)->nullable();
            }
            if (!Schema::hasColumn('terceros', 'es_cliente')) {
                $table->boolean('es_cliente')->default(true);
            }
            if (!Schema::hasColumn('terceros', 'es_proveedor')) {
                $table->boolean('es_proveedor')->default(false);
            }
            if (!Schema::hasColumn('terceros', 'es_empleado')) {
                $table->boolean('es_empleado')->default(false);
            }
            if (!Schema::hasColumn('terceros', 'activo')) {
                $table->boolean('activo')->default(true);
            }
        });
    }

    public function down(): void
    {
        // No revertimos para evitar pérdida accidental de datos en reparación
    }
};
