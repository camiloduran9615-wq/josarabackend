<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Remision extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'remisiones';

    protected $fillable = [
        'centro_costo_id', 'tercero_id', 'factura_id', 'numero', 'fecha', 'fecha_entrega',
        'direccion_entrega', 'transportista', 'numero_guia',
        'valor_total', 'estado', 'observaciones',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_entrega' => 'date',
        'valor_total' => 'decimal:2',
    ];

    public function tercero()
    {
        return $this->belongsTo(Tercero::class);
    }

    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }

    public function items()
    {
        return $this->hasMany(RemisionItem::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
