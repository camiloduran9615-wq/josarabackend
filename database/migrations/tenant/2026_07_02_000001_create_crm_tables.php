<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Básico — Prospectos y Oportunidades.
 *
 * prospectos    → leads / clientes potenciales
 * oportunidades → negociaciones activas con valor estimado y etapa
 * actividades_crm → seguimiento de llamadas, correos, reuniones
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospectos', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('razon_social', 200);
            $table->string('contacto_nombre', 100)->nullable();
            $table->string('contacto_email')->nullable();
            $table->string('contacto_telefono', 20)->nullable();
            $table->string('ciudad', 80)->nullable();
            $table->string('sector', 80)->nullable();           // tecnologia, retail, manufactura, etc.
            $table->string('fuente', 50)->nullable();           // referido, web, evento, llamada_fria
            $table->string('estado', 30)->default('activo');    // activo, convertido, descartado
            $table->uuid('responsable_id')->nullable();         // user asignado
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('oportunidades', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('prospecto_id')->nullable();
            $table->foreign('prospecto_id')->references('id')->on('prospectos')->nullOnDelete();
            $table->uuid('tercero_id')->nullable();             // si ya es cliente en el sistema
            $table->string('nombre', 200);
            $table->string('etapa', 30)->default('prospecto');  // prospecto, calificado, propuesta, negociacion, cerrado_ganado, cerrado_perdido
            $table->decimal('valor_estimado', 18, 4)->default(0);
            $table->integer('probabilidad')->default(0);        // 0-100%
            $table->date('fecha_cierre_esperada')->nullable();
            $table->uuid('cotizacion_id')->nullable();          // vincula a cotización existente
            $table->uuid('responsable_id')->nullable();
            $table->text('notas')->nullable();
            $table->string('motivo_perdida', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('actividades_crm', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oportunidad_id')->nullable();
            $table->foreign('oportunidad_id')->references('id')->on('oportunidades')->nullOnDelete();
            $table->uuid('prospecto_id')->nullable();
            $table->foreign('prospecto_id')->references('id')->on('prospectos')->nullOnDelete();
            $table->string('tipo', 30);                         // llamada, correo, reunion, demo, propuesta
            $table->string('asunto', 200);
            $table->text('descripcion')->nullable();
            $table->timestamp('fecha_actividad');
            $table->string('resultado', 30)->nullable();        // exitoso, sin_respuesta, reagendado, cancelado
            $table->uuid('creado_por_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividades_crm');
        Schema::dropIfExists('oportunidades');
        Schema::dropIfExists('prospectos');
    }
};
