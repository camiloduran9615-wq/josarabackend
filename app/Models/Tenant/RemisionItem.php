<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RemisionItem extends Model
{
    use HasUuids;

    protected $table = 'remision_items';

    protected $fillable = [
        'remision_id', 'producto_id', 'codigo_referencia', 'nombre',
        'cantidad', 'unidad_medida', 'precio_unitario', 'total',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
