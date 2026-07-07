<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodoNomina extends Model
{
    use HasUuids;

    protected $table = 'periodos_nomina';

    protected $fillable = [
        'codigo', 'tipo', 'fecha_inicio', 'fecha_fin',
        'año', 'mes', 'quincena', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin'    => 'date',
            'año'          => 'integer',
            'mes'          => 'integer',
            'quincena'     => 'integer',
        ];
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionNomina::class, 'periodo_nomina_id');
    }
}
