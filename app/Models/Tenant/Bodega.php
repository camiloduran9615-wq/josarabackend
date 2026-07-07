<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bodega extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'bodegas';

    protected $fillable = [
        'sucursal_id',
        'codigo',
        'nombre',
        'tipo',
        'inventario_cuenta_id',
        'responsable_user_id',
        'es_principal',
        'activa',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'activa'       => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function inventarioCuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'inventario_cuenta_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'responsable_user_id');
    }

    public function stockProductos(): HasMany
    {
        return $this->hasMany(ProductoStockBodega::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeDeSucursal($query, string $sucursalId)
    {
        return $query->where('sucursal_id', $sucursalId);
    }
}
