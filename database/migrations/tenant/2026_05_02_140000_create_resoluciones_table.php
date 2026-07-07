<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resoluciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre'); // Ej: Facturación Electrónica 2026
            $table->string('prefijo')->nullable();
            $table->integer('desde');
            $table->integer('hasta');
            $table->string('numero_resolucion');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->integer('factus_id')->nullable(); // ID en el sistema de Factus
            
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resoluciones');
    }
};
