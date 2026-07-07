<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla pivote que almacena el stock de un producto en una bodega específica.
 *
 * IMPORTANTE: Esta tabla NO debe actualizarse directamente.
 * Usar CostoPromedioService::registrarEntrada() / registrarSalida()
 * para garantizar consistencia del CPP y del KARDEX.
 */
class ProductoStockBodega extends Model
{
    use HasUuids;

    protected $table = 'producto_stock_bodega';

    protected $fillable = [
        'producto_id',
        'bodega_id',
        'saldo_unidades',
        'saldo_valor',
        'costo_promedio',
        'ultima_entrada_at',
        'ultima_salida_at',
        'version',
    ];

    protected $casts = [
        'saldo_unidades'   => 'decimal:4',
        'saldo_valor'      => 'decimal:2',
        'costo_promedio'   => 'decimal:4',
        'ultima_entrada_at'=> 'datetime',
        'ultima_salida_at' => 'datetime',
        'version'          => 'integer',
    ];

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function tieneStock(float $cantidad): bool
    {
        return (float) $this->saldo_unidades >= $cantidad;
    }
}
