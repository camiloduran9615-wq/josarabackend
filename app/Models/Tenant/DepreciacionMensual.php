<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento mensual de depreciación.
 *
 * Cada fila documenta cuánto se depreció un activo en un mes específico
 * y referencia el asiento contable que se generó.
 *
 * Restricción única (activo_fijo_id, anio, mes) impide doble depreciación.
 */
class DepreciacionMensual extends Model
{
    use HasUuids;

    protected $table = 'depreciaciones_mensuales';

    protected $fillable = [
        'activo_fijo_id',
        'asiento_id',
        'anio',
        'mes',
        'valor_depreciacion',
        'depreciacion_acumulada_al_cierre',
    ];

    protected $casts = [
        'anio'                             => 'integer',
        'mes'                              => 'integer',
        'valor_depreciacion'               => 'decimal:2',
        'depreciacion_acumulada_al_cierre' => 'decimal:2',
    ];

    public function activoFijo(): BelongsTo
    {
        return $this->belongsTo(ActivoFijo::class);
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }
}
