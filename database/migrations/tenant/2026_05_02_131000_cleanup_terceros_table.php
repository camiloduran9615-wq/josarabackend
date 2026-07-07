<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceros', function (Blueprint $table) {
            // Eliminar columnas antiguas si existen para evitar conflictos de NOT NULL
            $columnsToRemove = [
                'tipo_identificacion', 
                'numero_identificacion', 
                'nombres', 
                'apellidos', 
                'nombre_completo'
            ];

            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('terceros', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // No revertimos para evitar errores en reparación
    }
};
