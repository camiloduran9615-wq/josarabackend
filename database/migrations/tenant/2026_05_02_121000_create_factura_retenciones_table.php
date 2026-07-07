<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factura_retenciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('factura_id')->constrained('facturas')->onDelete('cascade');
            $table->string('codigo'); // 05, 06, 07
            $table->string('nombre'); // Retefuente, etc.
            $table->decimal('tasa', 5, 2);
            $table->decimal('valor', 15, 2);
            $table->decimal('base', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_retenciones');
    }
};
