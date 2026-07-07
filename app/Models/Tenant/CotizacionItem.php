<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CotizacionItem extends Model
{
    use HasUuids;

    protected $table = 'cotizacion_items';

    protected $fillable = [
        'cotizacion_id', 'producto_id', 'codigo_referencia', 'nombre',
        'descripcion', 'cantidad', 'unidad_medida', 'precio_unitario',
        'porcentaje_descuento', 'porcentaje_iva', 'valor_iva', 'total',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'porcentaje_descuento' => 'decimal:2',
        'porcentaje_iva' => 'decimal:2',
        'valor_iva' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
