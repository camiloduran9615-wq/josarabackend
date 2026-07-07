<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FacturaItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'factura_id',
        'codigo_referencia',
        'nombre',
        'cantidad',
        'precio_unitario',
        'porcentaje_descuento',
        'porcentaje_iva',
        'valor_iva',
        'total',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }
}
