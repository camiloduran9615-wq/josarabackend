<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida_dian', function (Blueprint $table) {
            $table->string('codigo', 20)->primary();
            $table->string('nombre', 120);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->boolean('sistema')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('unidades_medida_dian')->insert([
            ['codigo' => '94',  'nombre' => 'Unidad', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => '70',  'nombre' => 'Actividad', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'KGM', 'nombre' => 'Kilogramo', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'GRM', 'nombre' => 'Gramo', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'TNE', 'nombre' => 'Tonelada métrica', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'LTR', 'nombre' => 'Litro', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MLT', 'nombre' => 'Mililitro', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MTR', 'nombre' => 'Metro', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'CMT', 'nombre' => 'Centímetro', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MMT', 'nombre' => 'Milímetro', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MTK', 'nombre' => 'Metro cuadrado', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'CMK', 'nombre' => 'Centímetro cuadrado', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MTQ', 'nombre' => 'Metro cúbico', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'CMQ', 'nombre' => 'Centímetro cubico', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'HUR', 'nombre' => 'Hora', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MIN', 'nombre' => 'Minuto', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'DAY', 'nombre' => 'Día', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'WEE', 'nombre' => 'Semana', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'MON', 'nombre' => 'Mes', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'ANN', 'nombre' => 'Año', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'SET', 'nombre' => 'Kit / Conjunto', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'PAR', 'nombre' => 'Par', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'DZN', 'nombre' => 'Docena', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'GLL', 'nombre' => 'Galón americano', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'BX',  'nombre' => 'Caja', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'BG',  'nombre' => 'Bolsa', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'BO',  'nombre' => 'Botella', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'PK',  'nombre' => 'Paquete', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'RL',  'nombre' => 'Rollo', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'ST',  'nombre' => 'Hoja', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'GL',  'nombre' => 'Galón', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'E48', 'nombre' => 'Servicio', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'ZZ',  'nombre' => 'Ítem mutuamente definido', 'descripcion' => null, 'activo' => true, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida_dian');
    }
};
