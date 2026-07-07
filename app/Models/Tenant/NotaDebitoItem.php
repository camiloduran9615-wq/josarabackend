<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NotaDebitoItem extends Model
{
    use HasUuids;

    protected $table = 'nota_debito_items';

    protected $fillable = [
        'nota_debito_id', 'nombre', 'cantidad',
        'precio_unitario', 'porcentaje_iva', 'valor_iva', 'total',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'valor_iva' => 'decimal:2',
        'total' => 'decimal:2',
    ];
}
