<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla de control de consecutivos (compuesta: tipo_comprobante + año_fiscal).
 * No usa UUID porque la PK es compuesta.
 */
class ConsecutivoAsiento extends Model
{
    protected $table = 'consecutivos_asientos';

    /** PK compuesta. */
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'tipo_comprobante',
        'año_fiscal',
        'ultimo_consecutivo',
    ];

    protected function casts(): array
    {
        return [
            'año_fiscal'         => 'integer',
            'ultimo_consecutivo' => 'integer',
        ];
    }
}
