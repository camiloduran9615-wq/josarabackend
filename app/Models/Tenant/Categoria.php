<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use HasUuids;

    protected $table = 'categorias';

    protected $fillable = [
        'nombre',
        'tipo',
        'inventario_cuenta_id',
        'ingresos_cuenta_id',
        'costo_ventas_cuenta_id',
        'devolucion_compras_cuenta_id',
        'devolucion_ventas_cuenta_id',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function inventarioCuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'inventario_cuenta_id');
    }

    public function ingresosCuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'ingresos_cuenta_id');
    }

    public function costoVentasCuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'costo_ventas_cuenta_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
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

    // ── Mapeo tipo → clave de parametrización ───────────────────────────────

    /**
     * Retorna la clave de parametrización contable que corresponde
     * a la cuenta de inventario de este tipo de categoría.
     * Usado como fallback cuando la categoría no tiene cuenta explícita.
     */
    public function claveParametrizacionInventario(): string
    {
        // Claves deben coincidir exactamente con las registradas en parametrizacion_contable
        return match($this->tipo) {
            'materia_prima'      => 'compra.cuenta_inventario_mp',
            'producto_proceso'   => 'compra.cuenta_inventario_pp',
            'producto_terminado' => 'compra.cuenta_inventario_pt',
            'activo_fijo'        => 'compra.cuenta_activo_fijo',
            'servicio'           => 'compra.cuenta_gasto_general',
            default              => 'compra.cuenta_inventario_merc',
        };
    }
}
