<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotaDebito extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'notas_debito';

    protected $fillable = [
        'centro_costo_id', 'tercero_id', 'factura_id', 'numero', 'fecha',
        'concepto_codigo', 'descripcion',
        'valor_bruto', 'valor_iva', 'valor_total',
        'cufe', 'public_url', 'estado', 'errores_api',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor_bruto' => 'decimal:2',
        'valor_iva' => 'decimal:2',
        'valor_total' => 'decimal:2',
        'errores_api' => 'array',
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
        return $this->hasMany(NotaDebitoItem::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
