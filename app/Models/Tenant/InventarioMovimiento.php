<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventarioMovimiento extends Model
{
    use HasUuids;

    protected $table = 'inventario_movimientos';

    protected $fillable = [
        // Identificación del movimiento
        'producto_id',
        'bodega_id',
        'bodega_destino_id',

        // Tipo y cantidades
        'tipo',
        'cantidad',
        'precio_unitario',
        'costo_unitario',
        'concepto',

        // Snapshot KARDEX (pre-calculado)
        'saldo_unidades_despues',
        'saldo_valor_despues',
        'costo_promedio_despues',

        // Referencias a documentos
        'factura_id',
        'documento_ingreso_id',
        'asiento_id',

        // Trazabilidad
        'tercero_id',
        'centro_costo_id',
    ];

    protected $casts = [
        'cantidad'                => 'decimal:4',
        'precio_unitario'         => 'decimal:4',
        'costo_unitario'          => 'decimal:4',
        'saldo_unidades_despues'  => 'decimal:4',
        'saldo_valor_despues'     => 'decimal:2',
        'costo_promedio_despues'  => 'decimal:4',
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

    public function bodegaDestino(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_destino_id');
    }

    public function documentoIngreso(): BelongsTo
    {
        return $this->belongsTo(DocumentoIngreso::class);
    }

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** ¿El movimiento suma al stock? */
    public function esEntrada(): bool
    {
        return in_array($this->tipo, [
            'entrada_compra',
            'traslado_entrada',
            'devolucion_venta',
            'ajuste_positivo',
            'produccion_terminado',
        ]);
    }

    public function esSalida(): bool
    {
        return ! $this->esEntrada();
    }
}
