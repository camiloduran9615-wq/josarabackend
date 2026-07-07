<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega centro_costo_id a todos los documentos del sistema.
 *
 * El campo es NULLABLE en todos los casos: no es obligatorio en el registro,
 * pero sí recomendado para la segmentación de informes por área de negocio.
 *
 * Tablas afectadas:
 *   facturas, recibos_caja, notas_debito, remisiones,
 *   cotizaciones, ajustes_cartera, asientos, comprobantes_egreso
 */
return new class extends Migration
{
    private array $tables = [
        'facturas',
        'recibos_caja',
        'notas_debito',
        'remisiones',
        'cotizaciones',
        'ajustes_cartera',
        'asientos',
        'comprobantes_egreso',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignUuid('centro_costo_id')
                      ->nullable()
                      ->constrained('centros_costo')
                      ->nullOnDelete()
                      ->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('centro_costo_id');
            });
        }
    }
};
