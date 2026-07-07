<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NominaDian extends Model
{
    use HasUuids;

    protected $table = 'nomina_dian';

    protected $fillable = [
        'liquidacion_id', 'cune', 'numero_documento',
        'xml_generado', 'xml_respuesta_dian',
        'estado_dian', 'mensaje_dian',
        'enviado_at', 'respondido_at',
    ];

    protected function casts(): array
    {
        return [
            'enviado_at'    => 'datetime',
            'respondido_at' => 'datetime',
        ];
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionNomina::class, 'liquidacion_id');
    }
}
