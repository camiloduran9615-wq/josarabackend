<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TipoComprobante extends Model
{
    use HasUuids;

    protected $table = 'tipo_comprobantes';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo_documento',
        'resolucion_id',
        'consecutivo_actual',
        'prefijo_override',
        'observaciones_default',
        'habilitar_rete_iva',
        'habilitar_rete_ica',
        'habilitar_autorretencion',
        'titulo_pdf',
        'cuenta_ventas_id',
        'cuenta_clientes_id',
        'cuenta_iva_id',
        'vendedor_id',
        'activo',
    ];

    protected $casts = [
        'activo'                  => 'boolean',
        'consecutivo_actual'      => 'integer',
        'habilitar_rete_iva'      => 'boolean',
        'habilitar_rete_ica'      => 'boolean',
        'habilitar_autorretencion'=> 'boolean',
    ];

    public function resolucion()
    {
        return $this->belongsTo(Resolucion::class);
    }
}
