<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activos_fijos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 30)->unique();
            $t->string('descripcion', 255);
            $t->string('categoria', 30); // edificios, equipo_oficina, vehiculos, muebles_enseres, equipo_computo, maquinaria
            $t->decimal('costo_adquisicion', 18, 2);
            $t->date('fecha_adquisicion');
            $t->unsignedSmallInteger('vida_util_meses'); // ej: 240 meses (20 años) edificios
            $t->decimal('valor_residual', 18, 2)->default(0);
            $t->decimal('depreciacion_acumulada', 18, 2)->default(0);

            // Fechas de depreciación: cuándo empieza y cuándo se calculó por última vez.
            // Permite no depreciar el mes de adquisición si la política contable así lo define.
            $t->date('fecha_inicio_depreciacion')->nullable();
            $t->date('ultima_depreciacion')->nullable();

            // Asociaciones opcionales para analítica
            $t->uuid('tercero_id')->nullable();   // proveedor de quien se compró
            $t->uuid('sucursal_id')->nullable();
            $t->uuid('centro_costo_id')->nullable();

            // Cuentas contables (parametrización inline por activo).
            // Permite que cada categoría use distintas cuentas (152405 edificios,
            // 152805 muebles, 152410 equipo cómputo, etc.).
            $t->uuid('cuenta_activo_id');
            $t->uuid('cuenta_depreciacion_acumulada_id');
            $t->uuid('cuenta_gasto_depreciacion_id');

            $t->string('estado', 20)->default('activo'); // activo | vendido | dado_de_baja
            $t->date('fecha_baja')->nullable();
            $t->text('notas')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('tercero_id')->references('id')->on('terceros')->nullOnDelete();
            $t->foreign('sucursal_id')->references('id')->on('sucursales')->nullOnDelete();
            $t->foreign('centro_costo_id')->references('id')->on('centros_costo')->nullOnDelete();
            $t->foreign('cuenta_activo_id')->references('id')->on('cuentas_contables')->restrictOnDelete();
            $t->foreign('cuenta_depreciacion_acumulada_id')->references('id')->on('cuentas_contables')->restrictOnDelete();
            $t->foreign('cuenta_gasto_depreciacion_id')->references('id')->on('cuentas_contables')->restrictOnDelete();

            $t->index('categoria');
            $t->index('estado');
            $t->index('fecha_adquisicion');
        });

        // Tabla de movimientos de depreciación (auditoría mensual).
        // Cada fila representa la depreciación de UN activo en UN mes.
        Schema::create('depreciaciones_mensuales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('activo_fijo_id');
            $t->uuid('asiento_id')->nullable(); // asiento contable generado
            $t->smallInteger('anio');
            $t->smallInteger('mes');
            $t->decimal('valor_depreciacion', 18, 2);
            $t->decimal('depreciacion_acumulada_al_cierre', 18, 2);
            $t->timestamps();

            $t->foreign('activo_fijo_id')->references('id')->on('activos_fijos')->cascadeOnDelete();
            $t->foreign('asiento_id')->references('id')->on('asientos')->nullOnDelete();

            // Unique: no depreciar dos veces el mismo activo en el mismo mes
            $t->unique(['activo_fijo_id', 'anio', 'mes'], 'uq_depreciacion_activo_mes');
            $t->index(['anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciaciones_mensuales');
        Schema::dropIfExists('activos_fijos');
    }
};
