<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionLinea extends Model
{
    use HasUuids;

    protected $table = 'liquidacion_lineas';

    protected $fillable = [
        'liquidacion_id', 'concepto_id',
        'cantidad', 'valor_unitario', 'valor_total',
        'tipo', 'nota',
    ];

    protected function casts(): array
    {
        return [
            'cantidad'       => 'decimal:4',
            'valor_unitario' => 'decimal:4',
            'valor_total'    => 'decimal:4',
        ];
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoNomina::class, 'concepto_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionNomina::class, 'liquidacion_id');
    }
}
