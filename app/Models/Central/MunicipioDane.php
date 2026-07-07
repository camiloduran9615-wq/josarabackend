<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo nacional DANE de municipios.
 *
 * Vive en BD central. Lectura compartida entre todos los tenants.
 * PK natural: `codigo_dane` (8 chars, formato 'DDMMM' donde DD=depto, MMM=municipio).
 *
 * @property string      $codigo_dane
 * @property string      $municipio_nombre
 * @property string      $departamento_dane
 * @property string      $departamento_nombre
 * @property string|null $region
 * @property bool        $activo
 */
class MunicipioDane extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'municipios_dane';

    protected $primaryKey = 'codigo_dane';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'codigo_dane',
        'municipio_nombre',
        'departamento_dane',
        'departamento_nombre',
        'region',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Tarifas ICA asociadas a este municipio.
     *
     * @return HasMany<TarifaIca, $this>
     */
    public function tarifasIca(): HasMany
    {
        return $this->hasMany(TarifaIca::class, 'municipio_dane', 'codigo_dane');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Búsqueda por nombre (fuzzy si PG con pg_trgm, ilike fallback).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBuscar(Builder $query, string $term): Builder
    {
        return $query->where('municipio_nombre', 'ilike', "%{$term}%");
    }
}
