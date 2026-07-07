<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoIngresoItem extends Model
{
    use HasUuids;

    protected $table = 'documento_ingreso_items';

    protected $fillable = [
        'documento_ingreso_id',
        'producto_id',
        'bodega_id',        // ← campo nuevo (multi-bodega)
        'cuenta_id',
        'tipo_linea',       // ← campo nuevo: 'producto' | 'gasto' | 'activo_fijo'
        'descripcion',
        'cantidad',
        'precio_unitario',
        'porcentaje_iva',
        'valor_iva',
        'total',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:4',
        'precio_unitario' => 'decimal:4',
        'porcentaje_iva'  => 'decimal:2',
        'valor_iva'       => 'decimal:2',
        'total'           => 'decimal:2',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoIngreso::class, 'documento_ingreso_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }
}
