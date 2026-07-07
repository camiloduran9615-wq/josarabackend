<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapa configurable: clave canónica → cuenta contable.
 * Usado por ContabilizadorService para generar asientos derivados.
 */
class ParametrizacionContable extends Model
{
    use HasUuids;
    use Auditable;

    protected $table = 'parametrizacion_contable';

    protected $fillable = [
        'clave',
        'cuenta_contable_id',
        'condiciones',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'condiciones' => 'array',
            'activo'      => 'boolean',
        ];
    }

    public function auditableActionPrefix(): string
    {
        return 'parametrizacion';
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }
}
