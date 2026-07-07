<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LiquidacionNomina extends Model
{
    use HasUuids;

    protected $table = 'liquidaciones_nomina';

    protected $fillable = [
        'periodo_nomina_id', 'empleado_id', 'contrato_id',
        'total_devengado', 'total_deduccion', 'neto_pagar',
        'estado', 'asiento_id', 'dias_laborados',
    ];

    protected function casts(): array
    {
        return [
            'total_devengado' => 'decimal:4',
            'total_deduccion' => 'decimal:4',
            'neto_pagar'      => 'decimal:4',
            'dias_laborados'  => 'integer',
        ];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoNomina::class, 'periodo_nomina_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoLaboral::class, 'contrato_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LiquidacionLinea::class, 'liquidacion_id');
    }

    public function nominaDian(): HasOne
    {
        return $this->hasOne(NominaDian::class, 'liquidacion_id');
    }
}
