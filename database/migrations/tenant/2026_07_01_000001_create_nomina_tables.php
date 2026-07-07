<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Nómina Electrónica DIAN — Tablas base.
 *
 * empleados          → registro del trabajador vinculado a la empresa
 * contratos_laborales → cada vinculación (puede haber varios por empleado)
 * conceptos_nomina   → catálogo de devengados y deducciones parametrizable
 * periodos_nomina    → quincena o mes a liquidar
 * liquidaciones_nomina → encabezado de la liquidación por empleado × periodo
 * liquidacion_lineas  → cada devengado/deducción de la liquidación
 * nomina_dian         → estado del envío a la DIAN (XML UBL + respuesta)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Empleados ────────────────────────────────────────────────────
        Schema::create('empleados', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // Datos personales
            $table->string('tipo_documento', 10);          // CC, CE, PA, NIT
            $table->string('numero_documento', 20)->unique();
            $table->string('primer_nombre', 80);
            $table->string('segundo_nombre', 80)->nullable();
            $table->string('primer_apellido', 80);
            $table->string('segundo_apellido', 80)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 20)->nullable();
            // Datos bancarios para pago
            $table->string('banco', 80)->nullable();
            $table->string('tipo_cuenta', 20)->nullable();   // ahorros, corriente
            $table->string('numero_cuenta', 30)->nullable();
            // Tercero vinculado (para asientos CxP)
            $table->uuid('tercero_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Contratos laborales ──────────────────────────────────────────
        Schema::create('contratos_laborales', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('empleado_id');
            $table->foreign('empleado_id')->references('id')->on('empleados');
            $table->string('tipo_contrato', 30);             // indefinido, fijo, obra_labor, aprendizaje
            $table->string('tipo_trabajador', 30)->default('dependiente'); // dependiente, pensionado, aprendiz
            $table->string('subtipo_trabajador', 30)->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();            // null = indefinido
            $table->decimal('salario_basico', 18, 4);
            $table->integer('dias_trabajo')->default(30);    // para liquidación mensual
            $table->string('cargo', 100)->nullable();
            $table->string('departamento', 100)->nullable();
            $table->uuid('sucursal_id')->nullable();
            $table->boolean('alto_riesgo')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Conceptos de nómina ──────────────────────────────────────────
        Schema::create('conceptos_nomina', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->string('tipo', 20);                      // devengado, deduccion
            $table->string('subtipo', 30);                   // basico, hora_extra, prima, vacacion, cesantia, salud, pension, retefuente, ica, embargo, libranza
            $table->boolean('aplica_seguridad_social')->default(false);
            $table->boolean('aplica_retefuente')->default(false);
            $table->boolean('es_prestacion_social')->default(false);
            $table->uuid('cuenta_contable_id')->nullable();  // cuenta PUC asociada
            $table->boolean('sistema')->default(false);      // creados por seeder
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // ── Períodos de nómina ───────────────────────────────────────────
        Schema::create('periodos_nomina', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('codigo', 20)->unique();
            $table->string('tipo', 20)->default('mensual'); // mensual, quincenal
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->integer('año');
            $table->integer('mes');
            $table->integer('quincena')->nullable();         // 1 o 2 si es quincenal
            $table->string('estado', 20)->default('abierto'); // abierto, liquidado, transmitido, cerrado
            $table->timestamps();
        });

        // ── Liquidaciones (encabezado por empleado × periodo) ────────────
        Schema::create('liquidaciones_nomina', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('periodo_nomina_id');
            $table->foreign('periodo_nomina_id')->references('id')->on('periodos_nomina');
            $table->uuid('empleado_id');
            $table->foreign('empleado_id')->references('id')->on('empleados');
            $table->uuid('contrato_id');
            $table->foreign('contrato_id')->references('id')->on('contratos_laborales');
            // Totales calculados
            $table->decimal('total_devengado', 18, 4)->default(0);
            $table->decimal('total_deduccion', 18, 4)->default(0);
            $table->decimal('neto_pagar', 18, 4)->default(0);
            // Estado DIAN
            $table->string('estado', 20)->default('borrador'); // borrador, aprobado, transmitido, rechazado
            $table->uuid('asiento_id')->nullable();            // asiento contable generado
            $table->integer('dias_laborados')->default(30);
            $table->timestamps();
            $table->unique(['periodo_nomina_id', 'empleado_id']);
        });

        // ── Líneas de la liquidación (devengados y deducciones) ──────────
        Schema::create('liquidacion_lineas', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('liquidacion_id');
            $table->foreign('liquidacion_id')->references('id')->on('liquidaciones_nomina')->cascadeOnDelete();
            $table->uuid('concepto_id');
            $table->foreign('concepto_id')->references('id')->on('conceptos_nomina');
            $table->decimal('cantidad', 10, 4)->default(1);   // horas, días, etc.
            $table->decimal('valor_unitario', 18, 4)->default(0);
            $table->decimal('valor_total', 18, 4)->default(0);
            $table->string('tipo', 20);                        // devengado | deduccion
            $table->text('nota')->nullable();
            $table->timestamps();
        });

        // ── Envío DIAN (documento soporte XML) ───────────────────────────
        Schema::create('nomina_dian', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('liquidacion_id')->unique();
            $table->foreign('liquidacion_id')->references('id')->on('liquidaciones_nomina');
            $table->string('cune', 96)->nullable();            // Código Único Nómina Electrónica
            $table->string('numero_documento', 40)->nullable();
            $table->text('xml_generado')->nullable();
            $table->text('xml_respuesta_dian')->nullable();
            $table->string('estado_dian', 20)->default('pendiente'); // pendiente, aceptado, rechazado, aceptado_con_errores
            $table->string('mensaje_dian')->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('respondido_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_dian');
        Schema::dropIfExists('liquidacion_lineas');
        Schema::dropIfExists('liquidaciones_nomina');
        Schema::dropIfExists('periodos_nomina');
        Schema::dropIfExists('conceptos_nomina');
        Schema::dropIfExists('contratos_laborales');
        Schema::dropIfExists('empleados');
    }
};
