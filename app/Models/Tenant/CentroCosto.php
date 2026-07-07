<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCosto extends Model
{
    use HasUuids;

    protected $table = 'centros_costo';

    protected $fillable = [
        'codigo',
        'nombre',
        'sucursal_id',
        'parent_id',
        'nivel',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'nivel'  => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** Centro padre (null si es raíz) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'parent_id');
    }

    /** Hijos directos, ordenados por código */
    public function children(): HasMany
    {
        return $this->hasMany(CentroCosto::class, 'parent_id')
                    ->orderBy('codigo');
    }

    /** Todos los descendientes (recursivo, cargado con eager loading) */
    public function allDescendants(): HasMany
    {
        return $this->children()->with('allDescendants');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeRaices($query)
    {
        return $query->whereNull('parent_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Devuelve la ruta legible del centro:
     *   "Ventas > Región Norte > Bogotá"
     */
    public function getBreadcrumbAttribute(): string
    {
        $parts = [$this->nombre];
        $current = $this;

        while ($current->parent_id !== null) {
            $current = $current->parent;
            if ($current === null) break;
            array_unshift($parts, $current->nombre);
        }

        return implode(' › ', $parts);
    }
}
