<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Activo Fijo (Propiedad, Planta y Equipo — NIC 16).
 *
 * Estados:
 *  - activo:        en uso, sigue depreciando
 *  - vendido:       enajenado; baja del balance
 *  - dado_de_baja:  retirado por obsolescencia/destrucción
 *
 * Depreciación (método línea recta):
 *  depreciacion_mensual = (costo_adquisicion - valor_residual) / vida_util_meses
 *
 * Vida útil típica DIAN/NIIF (Decreto 1625/2016, ajustada NIC 16):
 *  - Edificios:          240–600 meses (20–50 años)
 *  - Maquinaria:         120 meses (10 años)
 *  - Vehículos:           60 meses (5 años)
 *  - Equipo de cómputo:   36–60 meses (3–5 años)
 *  - Muebles y enseres:  120 meses (10 años)
 */
class ActivoFijo extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const CATEGORIAS = [
        'edificios',
        'equipo_oficina',
        'vehiculos',
        'muebles_enseres',
        'equipo_computo',
        'maquinaria',
    ];

    public const ESTADO_ACTIVO        = 'activo';
    public const ESTADO_VENDIDO       = 'vendido';
    public const ESTADO_DADO_DE_BAJA  = 'dado_de_baja';

    protected $table = 'activos_fijos';

    protected $fillable = [
        'codigo',
        'descripcion',
        'categoria',
        'costo_adquisicion',
        'fecha_adquisicion',
        'vida_util_meses',
        'valor_residual',
        'depreciacion_acumulada',
        'fecha_inicio_depreciacion',
        'ultima_depreciacion',
        'tercero_id',
        'sucursal_id',
        'centro_costo_id',
        'cuenta_activo_id',
        'cuenta_depreciacion_acumulada_id',
        'cuenta_gasto_depreciacion_id',
        'estado',
        'fecha_baja',
        'notas',
    ];

    protected $casts = [
        'costo_adquisicion'         => 'decimal:2',
        'valor_residual'            => 'decimal:2',
        'depreciacion_acumulada'    => 'decimal:2',
        'fecha_adquisicion'         => 'date',
        'fecha_inicio_depreciacion' => 'date',
        'ultima_depreciacion'       => 'date',
        'fecha_baja'                => 'date',
        'vida_util_meses'           => 'integer',
    ];

    /**
     * Depreciación mensual por línea recta.
     */
    public function depreciacionMensual(): float
    {
        if ($this->vida_util_meses <= 0) {
            return 0.0;
        }
        $base = (float) $this->costo_adquisicion - (float) $this->valor_residual;
        return round($base / $this->vida_util_meses, 2);
    }

    /**
     * Cuántos meses se han depreciado hasta una fecha dada.
     */
    public function mesesDepreciadosHasta(\DateTimeInterface $fecha): int
    {
        $inicio = $this->fecha_inicio_depreciacion ?? $this->fecha_adquisicion;
        if ($inicio === null || $fecha < $inicio) {
            return 0;
        }
        $start = \Carbon\CarbonImmutable::parse($inicio)->startOfMonth();
        $end   = \Carbon\CarbonImmutable::parse($fecha)->startOfMonth();
        return max(0, (int) $start->diffInMonths($end));
    }

    /**
     * Valor neto contable = costo - depreciación acumulada.
     */
    public function valorNeto(): float
    {
        return round((float) $this->costo_adquisicion - (float) $this->depreciacion_acumulada, 2);
    }

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class);
    }

    public function cuentaActivo(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_activo_id');
    }

    public function cuentaDepreciacionAcumulada(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_depreciacion_acumulada_id');
    }

    public function cuentaGastoDepreciacion(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_gasto_depreciacion_id');
    }

    public function depreciacionesMensuales(): HasMany
    {
        return $this->hasMany(DepreciacionMensual::class);
    }

    public function esActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
