<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Tenant\Resolucion;

class Factura extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'centro_costo_id',
        'tipo_documento',
        'fecha_emision',
        'resolucion_id',
        'tercero_id',
        'numbering_range_id',
        'factus_bill_id',
        'prefijo',
        'numero',
        'numero_completo',
        'valor_bruto',
        'valor_impuestos',
        'valor_retenciones',
        'valor_descuentos',
        'valor_total',
        'reference_code',
        'cufe',
        'qr_url',
        'public_url',
        'estado',
        'observaciones',
        'errores_api',
        'fecha_validacion',
        'payment_form',
        'payment_method_code',
        'payment_due_date',
    ];

    protected $casts = [
        'errores_api'       => 'array',
        'fecha_validacion'  => 'datetime',
        'payment_due_date'  => 'date',
        'valor_bruto'       => 'decimal:2',
        'valor_impuestos'   => 'decimal:2',
        'valor_total'       => 'decimal:2',
    ];

    public function tercero()
    {
        return $this->belongsTo(Tercero::class);
    }

    public function resolucion()
    {
        return $this->belongsTo(Resolucion::class);
    }

    public function items()
    {
        return $this->hasMany(FacturaItem::class);
    }

    public function retenciones()
    {
        return $this->hasMany(FacturaRetencion::class);
    }

    public function notasCredito()
    {
        return $this->hasMany(NotaCredito::class);
    }
}
