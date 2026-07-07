<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comprobante de Egreso — pago realizado a un proveedor.
 *
 * Asiento generado:
 *   DÉBITO  cuenta_debito_id   (Cuentas por Pagar / 220505)
 *   CRÉDITO cuenta_credito_id  (Banco / Caja)
 */
class ComprobanteEgreso extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'comprobantes_egreso';

    protected $fillable = [
        'centro_costo_id',
        'tercero_id',
        'numero',
        'fecha',
        'concepto',
        'forma_pago',
        'banco',
        'numero_cheque',
        'referencia_pago',
        'cuenta_debito_id',
        'cuenta_credito_id',
        'valor_pagado',
        'facturas_aplicadas',
        'estado',
        'observaciones',
        'asiento_id',
    ];

    protected $casts = [
        'fecha'              => 'date',
        'valor_pagado'       => 'decimal:2',
        'facturas_aplicadas' => 'array',
    ];

    // ── Relaciones ─────────────────────────────────────────────────────────

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    public function cuentaDebito(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_debito_id');
    }

    public function cuentaCredito(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_credito_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
