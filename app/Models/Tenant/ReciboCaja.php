<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReciboCaja extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'recibos_caja';

    protected $fillable = [
        'centro_costo_id', 'tercero_id', 'numero', 'fecha', 'valor_recibido',
        'concepto', 'forma_pago', 'banco', 'numero_cheque',
        'referencia_pago', 'facturas_aplicadas', 'estado', 'observaciones',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor_recibido' => 'decimal:2',
        'facturas_aplicadas' => 'array',
    ];

    public function tercero()
    {
        return $this->belongsTo(Tercero::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
