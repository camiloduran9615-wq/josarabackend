<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo DANE de municipios de Colombia (DIVIPOLA).
 *
 * Modelo CENTRAL — vive en la BD central, compartido por todos los tenants.
 * Primary key: codigo_dane (5 dígitos: 2 depto + 3 municipio).
 *
 * Referenciado por:
 *   - tarifas_ica.municipio_dane (FK)
 *   - terceros.municipio_id (string match con codigo_dane)
 */
class MunicipioDane extends Model
{
    protected $connection = 'pgsql';

    protected $table      = 'municipios_dane';
    protected $primaryKey = 'codigo_dane';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected $fillable = [
        'codigo_dane',
        'municipio_nombre',
        'departamento_dane',
        'departamento_nombre',
        'region',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->municipio_nombre}, {$this->departamento_nombre}";
    }
}
