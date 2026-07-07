<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tipo de Documento de Ingreso parametrizable.
 *
 * Cada tipo define su propio comportamiento contable:
 *  - qué cuentas afecta (con override sobre parametrizacion_contable)
 *  - si mueve inventario o no
 *  - retenciones predeterminadas
 */
class TipoDocumentoIngreso extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tipos_documento_ingreso';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'prefijo_numero',
        'afecta_inventario',
        'tipo_linea_default',
        'cuenta_inventario_id',
        'cuenta_gasto_id',
        'cuenta_proveedor_id',
        'cuenta_iva_descontable_id',
        'retefuente_concepto',
        'retefuente_tasa',
        'reteica_concepto',
        'reteica_tasa',
        'activo',
    ];

    protected $casts = [
        'afecta_inventario' => 'boolean',
        'activo'            => 'boolean',
        'retefuente_tasa'   => 'decimal:4',
        'reteica_tasa'      => 'decimal:4',
    ];

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function cuentaInventario(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_inventario_id');
    }

    public function cuentaGasto(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_gasto_id');
    }

    public function cuentaProveedor(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_proveedor_id');
    }

    public function cuentaIvaDescontable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_iva_descontable_id');
    }
}
