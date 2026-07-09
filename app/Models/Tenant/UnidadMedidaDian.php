<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class UnidadMedidaDian extends Model
{
    protected $table = 'unidades_medida_dian';
    protected $primaryKey = 'codigo';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'activo',
        'sistema',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'sistema' => 'boolean',
    ];
}
