<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class AjusteCartera extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'ajustes_cartera';

    protected $fillable = [
        'centro_costo_id', 'tercero_id', 'factura_id', 'cuenta_debito_id', 'cuenta_credito_id',
        'numero', 'fecha', 'tipo', 'concepto', 'valor', 'estado', 'observaciones',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];

    public function tercero()
    {
        return $this->belongsTo(Tercero::class);
    }

    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }

    public function cuentaDebito()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_debito_id');
    }

    public function cuentaCredito()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_credito_id');
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
