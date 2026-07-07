<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tercero extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'terceros';

    protected $fillable = [
        'identificacion_documento_id',
        'identificacion',
        'dv',
        'organizacion_juridica_id',
        'tributo_id',
        'razon_social',
        'nombre_comercial',
        'direccion',
        'email',
        'telefono',
        'municipio_id',
        'es_cliente',
        'es_proveedor',
        'es_empleado',
        'activo',
        'tipo_persona',
        'sucursal',
        'nombres',
        'apellidos',
        'regimen_iva',
        'responsabilidades_fiscales',
        'codigo_postal',
        'nombre_contacto',
        'vendedor_id',
        'cobrador_id',
        'observaciones',
        'contactos_adicionales',
        'codigo_ciiu',
    ];

    protected $casts = [
        'es_cliente' => 'boolean',
        'es_proveedor' => 'boolean',
        'es_empleado' => 'boolean',
        'activo' => 'boolean',
        'responsabilidades_fiscales' => 'array',
        'contactos_adicionales' => 'array'
    ];
}
