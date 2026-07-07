<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega payment_due_date a facturas.
 *
 * Factus API exige este campo cuando payment_form = 2 (Crédito).
 * Es la fecha en que el cliente debe cancelar la factura.
 * Para payment_form = 1 (Contado) el campo se omite o queda null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->date('payment_due_date')
                  ->nullable()
                  ->after('payment_method_code')
                  ->comment('Fecha de vencimiento — obligatoria cuando payment_form = 2 (Crédito)');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn('payment_due_date');
        });
    }
};
