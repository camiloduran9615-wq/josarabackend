<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotaCredito extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'notas_credito';

    protected $fillable = [
        'factura_id',
        'numero',
        'numero_completo',
        'valor_total',
        'reference_code',
        'cufe',
        'public_url',
        'discrepancy_response_code',
        'discrepancy_response_description',
        'estado',
    ];

    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }
}
