<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipos de Documento de Ingreso parametrizables.
 *
 * Equivale a los "Tipos de Comprobante de Compra" en SIIGO:
 * cada tipo define su propio comportamiento contable, cuentas y retenciones.
 *
 * Ejemplos:
 *   FCI → Factura Compra Inventario  (afecta_inventario=true)
 *   FCG → Factura Compra Gastos      (afecta_inventario=false, tipo_linea=gasto)
 *   FCA → Factura Compra Activos     (afecta_inventario=false, tipo_linea=activo_fijo)
 *   CC  → Cuenta de Cobro            (retefuente_concepto=rf_honorarios, tasa=11%)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento_ingreso', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identificación
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 500)->nullable();
            $table->string('prefijo_numero', 10)->nullable(); // ej: "FCI", "FCG" para el consecutivo

            // ── Comportamiento ────────────────────────────────────────────────
            $table->boolean('afecta_inventario')->default(true);
            $table->string('tipo_linea_default', 20)->default('producto');
            // CHECK constraint
            $table->boolean('activo')->default(true);

            // ── Cuentas contables override (prioridad sobre parametrizacion_contable) ──
            // Si se dejan null, el sistema cae al fallback de parametrizacion_contable
            $table->foreignUuid('cuenta_inventario_id')
                  ->nullable()->constrained('cuentas_contables')->nullOnDelete();
            $table->foreignUuid('cuenta_gasto_id')
                  ->nullable()->constrained('cuentas_contables')->nullOnDelete();
            $table->foreignUuid('cuenta_proveedor_id')
                  ->nullable()->constrained('cuentas_contables')->nullOnDelete();
            $table->foreignUuid('cuenta_iva_descontable_id')
                  ->nullable()->constrained('cuentas_contables')->nullOnDelete();

            // ── Retenciones predeterminadas ──────────────────────────────────
            // concepto → clave del CONCEPTOS_RETENCION del frontend (rf_compras, rf_honorarios, ica_comercio…)
            $table->string('retefuente_concepto', 50)->nullable();
            $table->decimal('retefuente_tasa', 8, 4)->nullable();  // % (3.5, 11.0 …)
            $table->string('reteica_concepto', 50)->nullable();
            $table->decimal('reteica_tasa', 8, 4)->nullable();     // ‰ (0.414, 0.966 …)

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            "ALTER TABLE tipos_documento_ingreso
             ADD CONSTRAINT chk_tdi_tipo_linea
             CHECK (tipo_linea_default IN ('producto','gasto','activo_fijo'))"
        );

        // ── Tipos predefinidos (como los "fijos" de SIIGO) ─────────────────
        $now = now();
        DB::table('tipos_documento_ingreso')->insert([
            [
                'id'                    => \Illuminate\Support\Str::orderedUuid(),
                'codigo'                => 'FCI',
                'nombre'                => 'Factura Compra — Inventario',
                'descripcion'           => 'Compra de mercancía o materia prima que ingresa al inventario. Genera movimiento KARDEX.',
                'prefijo_numero'        => 'FCI',
                'afecta_inventario'     => true,
                'tipo_linea_default'    => 'producto',
                'cuenta_inventario_id'  => null,
                'cuenta_gasto_id'       => null,
                'cuenta_proveedor_id'   => null,
                'cuenta_iva_descontable_id' => null,
                'retefuente_concepto'   => 'rf_compras',
                'retefuente_tasa'       => 3.5,
                'reteica_concepto'      => null,
                'reteica_tasa'          => null,
                'activo'                => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => \Illuminate\Support\Str::orderedUuid(),
                'codigo'                => 'FCG',
                'nombre'                => 'Factura Compra — Gastos y Servicios',
                'descripcion'           => 'Compra de servicios o gastos operativos. NO afecta inventario.',
                'prefijo_numero'        => 'FCG',
                'afecta_inventario'     => false,
                'tipo_linea_default'    => 'gasto',
                'cuenta_inventario_id'  => null,
                'cuenta_gasto_id'       => null,
                'cuenta_proveedor_id'   => null,
                'cuenta_iva_descontable_id' => null,
                'retefuente_concepto'   => 'rf_servicios',
                'retefuente_tasa'       => 4.0,
                'reteica_concepto'      => 'ica_servicios',
                'reteica_tasa'          => 0.966,
                'activo'                => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => \Illuminate\Support\Str::orderedUuid(),
                'codigo'                => 'CC',
                'nombre'                => 'Cuenta de Cobro — Honorarios',
                'descripcion'           => 'Cuenta de cobro por prestación de servicios. Retefuente honorarios 11%.',
                'prefijo_numero'        => 'CC',
                'afecta_inventario'     => false,
                'tipo_linea_default'    => 'gasto',
                'cuenta_inventario_id'  => null,
                'cuenta_gasto_id'       => null,
                'cuenta_proveedor_id'   => null,
                'cuenta_iva_descontable_id' => null,
                'retefuente_concepto'   => 'rf_honorarios',
                'retefuente_tasa'       => 11.0,
                'reteica_concepto'      => null,
                'reteica_tasa'          => null,
                'activo'                => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => \Illuminate\Support\Str::orderedUuid(),
                'codigo'                => 'FCA',
                'nombre'                => 'Factura Compra — Activo Fijo',
                'descripcion'           => 'Compra de activos fijos (maquinaria, equipo, vehículos). Cuenta clase 15/16.',
                'prefijo_numero'        => 'FCA',
                'afecta_inventario'     => false,
                'tipo_linea_default'    => 'activo_fijo',
                'cuenta_inventario_id'  => null,
                'cuenta_gasto_id'       => null,
                'cuenta_proveedor_id'   => null,
                'cuenta_iva_descontable_id' => null,
                'retefuente_concepto'   => 'rf_compras',
                'retefuente_tasa'       => 3.5,
                'reteica_concepto'      => null,
                'reteica_tasa'          => null,
                'activo'                => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_documento_ingreso');
    }
};
