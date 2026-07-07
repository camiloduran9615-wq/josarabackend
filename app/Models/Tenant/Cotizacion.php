<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cotizacion extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'cotizaciones';

    protected $fillable = [
        'centro_costo_id', 'tercero_id', 'numero', 'fecha', 'fecha_validez',
        'condiciones_comerciales', 'observaciones',
        'valor_bruto', 'valor_descuento', 'valor_iva', 'valor_total',
        'estado',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_validez' => 'date',
        'valor_bruto' => 'decimal:2',
        'valor_descuento' => 'decimal:2',
        'valor_iva' => 'decimal:2',
        'valor_total' => 'decimal:2',
    ];

    public function tercero()
    {
        return $this->belongsTo(Tercero::class);
    }

    public function items()
    {
        return $this->hasMany(CotizacionItem::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
