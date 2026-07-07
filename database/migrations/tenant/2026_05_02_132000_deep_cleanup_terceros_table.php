<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceros', function (Blueprint $table) {
            $extraColumns = [
                'tipo_tercero', 
                'regimen_fiscal', 
                'responsabilidad_fiscal',
                'codigo_postal'
            ];

            foreach ($extraColumns as $column) {
                if (Schema::hasColumn('terceros', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
    }
};
