<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobantes de Egreso — pagos realizados a proveedores.
 *
 * Flujo contable:
 *   DÉBITO   Cuentas por Pagar (220505) ← cancela deuda con proveedor
 *   CRÉDITO  Banco / Caja               ← sale el dinero de la empresa
 *
 * Equivalente SIIGO: Módulo Proveedores → Comprobantes de Egreso
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_egreso', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Proveedor al que se le paga
            $table->foreignUuid('tercero_id')
                  ->constrained('terceros');

            // Numeración automática (CE-000001)
            $table->string('numero', 30)->unique();

            $table->date('fecha');
            $table->string('concepto', 500);

            // ── Forma de pago ────────────────────────────────────────────────
            $table->string('forma_pago', 30)->default('transferencia');
            // CHECK: efectivo | transferencia | cheque | consignacion | otro
            $table->string('banco', 100)->nullable();
            $table->string('numero_cheque', 50)->nullable();
            $table->string('referencia_pago', 100)->nullable();

            // ── Cuentas involucradas ─────────────────────────────────────────
            // La cuenta DÉBITO (lo que se cancela): proveedor u otra cuenta por pagar
            $table->foreignUuid('cuenta_debito_id')
                  ->constrained('cuentas_contables');

            // La cuenta CRÉDITO (de dónde sale el dinero): banco o caja
            $table->foreignUuid('cuenta_credito_id')
                  ->constrained('cuentas_contables');

            // ── Valor ────────────────────────────────────────────────────────
            $table->decimal('valor_pagado', 15, 2);

            // ── Facturas de compra que cubre (opcional, informativo) ─────────
            // Array de IDs: ["uuid1","uuid2"] — no FK, solo referencia
            $table->jsonb('facturas_aplicadas')->nullable();

            // ── Estado y trazabilidad ────────────────────────────────────────
            $table->string('estado', 20)->default('registrado');
            $table->string('observaciones', 1000)->nullable();

            // Asiento generado
            $table->foreignUuid('asiento_id')
                  ->nullable()
                  ->constrained('asientos')
                  ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            "ALTER TABLE comprobantes_egreso
             ADD CONSTRAINT chk_ce_forma_pago
             CHECK (forma_pago IN ('efectivo','transferencia','cheque','consignacion','otro'))"
        );

        DB::statement(
            "ALTER TABLE comprobantes_egreso
             ADD CONSTRAINT chk_ce_estado
             CHECK (estado IN ('borrador','registrado','anulado'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_egreso');
    }
};
