<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa configurable por tenant: clave canónica → cuenta contable.
 * Usado por ContabilizadorService para generar asientos derivados.
 * Ejemplos de claves:
 *   factura.cuenta_cartera, factura.cuenta_iva_generado_19,
 *   compra.cuenta_proveedor, recibo_caja.cuenta_banco, etc.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('parametrizacion_contable', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('clave', 100);
            $table->uuid('cuenta_contable_id');
            $table->json('condiciones')->nullable(); // ej: {"tarifa_iva": 19}
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('clave');
            $table->index(['clave', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametrizacion_contable');
    }
};
