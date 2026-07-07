<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FacturaRetencion extends Model
{
    use HasUuids;

    protected $table = 'factura_retenciones';

    protected $fillable = [
        'factura_id',
        'codigo',
        'nombre',
        'tasa',
        'valor',
        'base',
    ];

    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }
}
