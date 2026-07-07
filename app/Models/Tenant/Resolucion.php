<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Resolucion extends Model
{
    use HasUuids;
    
    protected $table = 'resoluciones';

    protected $fillable = [
        'nombre', 
        'prefijo', 
        'desde', 
        'hasta', 
        'numero_resolucion', 
        'fecha_inicio', 
        'fecha_fin', 
        'factus_id', 
        'activa'
    ];
}
