<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use HasUuids, SoftDeletes;
    
    protected $table = 'productos';

    protected $fillable = [
        'codigo', 
        'nombre', 
        'descripcion', 
        'unidad_medida', 
        'precio_venta', 
        'precio_compra', 
        'stock_actual', 
        'stock_minimo', 
        'categoria_id', 
        'porcentaje_iva', 
        'activo'
    ];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }
}
