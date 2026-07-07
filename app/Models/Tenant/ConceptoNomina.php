<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConceptoNomina extends Model
{
    use HasUuids;

    protected $table = 'conceptos_nomina';

    protected $fillable = [
        'codigo', 'nombre', 'tipo', 'subtipo',
        'aplica_seguridad_social', 'aplica_retefuente',
        'es_prestacion_social', 'cuenta_contable_id',
        'sistema', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'aplica_seguridad_social' => 'boolean',
            'aplica_retefuente'       => 'boolean',
            'es_prestacion_social'    => 'boolean',
            'sistema'                 => 'boolean',
            'activo'                  => 'boolean',
        ];
    }
}
