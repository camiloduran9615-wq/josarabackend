<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa ICA por municipio × código CIIU × rango de vigencia.
 *
 * Vive en BD central. Lectura compartida entre tenants.
 *
 * `tarifa_por_mil`: DECIMAL(7,4) — 9.6600 representa 9.66‰ (por mil).
 * Vigencia inclusiva: aplicable cuando
 *   activa AND vigencia_desde <= fecha AND (vigencia_hasta IS NULL OR vigencia_hasta >= fecha).
 *
 * @property string $id
 * @property string $municipio_dane
 * @property string $municipio_nombre
 * @property string $departamento_dane
 * @property string $codigo_actividad_ciiu
 * @property string $descripcion_actividad
 * @property string $tarifa_por_mil
 * @property string|null $base_minima_uvt
 * @property string|null $base_minima_cop
 * @property \Carbon\CarbonImmutable $vigencia_desde
 * @property \Carbon\CarbonImmutable|null $vigencia_hasta
 * @property bool   $activa
 * @property string|null $fuente_legal
 */
class TarifaIca extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';

    protected $table = 'tarifas_ica';

    protected $fillable = [
        'municipio_dane',
        'municipio_nombre',
        'departamento_dane',
        'codigo_actividad_ciiu',
        'descripcion_actividad',
        'tarifa_por_mil',
        'base_minima_uvt',
        'base_minima_cop',
        'vigencia_desde',
        'vigencia_hasta',
        'activa',
        'fuente_legal',
    ];

    protected function casts(): array
    {
        return [
            'tarifa_por_mil'  => 'decimal:4',
            'base_minima_uvt' => 'decimal:2',
            'base_minima_cop' => 'decimal:2',
            'vigencia_desde'  => 'immutable_date',
            'vigencia_hasta'  => 'immutable_date',
            'activa'          => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MunicipioDane, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(MunicipioDane::class, 'municipio_dane', 'codigo_dane');
    }

    /**
     * Scope: tarifa vigente a una fecha dada (default hoy) y activa.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVigentes(Builder $query, ?\DateTimeInterface $fecha = null): Builder
    {
        $fecha ??= new \DateTimeImmutable();

        return $query
            ->where('activa', true)
            ->where('vigencia_desde', '<=', $fecha)
            ->where(function (Builder $q) use ($fecha): void {
                $q->whereNull('vigencia_hasta')
                  ->orWhere('vigencia_hasta', '>=', $fecha);
            });
    }

    /**
     * Filtra por municipio (código DANE).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDelMunicipio(Builder $query, string $codigoDane): Builder
    {
        return $query->where('municipio_dane', $codigoDane);
    }
}
