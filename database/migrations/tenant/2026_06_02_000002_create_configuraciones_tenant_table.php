<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_tenant', function (Blueprint $table) {
            $table->string('clave')->primary();
            $table->text('valor')->nullable();
            $table->string('tipo')->default('string'); // string|boolean|integer|json
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        // Valores por defecto
        DB::table('configuraciones_tenant')->insert([
            ['clave' => 'inventario.permitir_stock_negativo', 'valor' => 'false', 'tipo' => 'boolean', 'descripcion' => 'Permite registrar salidas cuando no hay stock suficiente', 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'inventario.metodo_valuacion',        'valor' => 'cpp',   'tipo' => 'string',  'descripcion' => 'Método de valuación: cpp (costo promedio) o fifo', 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'contabilidad.decimales_asiento',     'valor' => '2',     'tipo' => 'integer', 'descripcion' => 'Decimales a usar en asientos contables (2 = COP)', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_tenant');
    }
};
