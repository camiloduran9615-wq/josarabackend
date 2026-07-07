<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContratoLaboral extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'contratos_laborales';

    protected $fillable = [
        'empleado_id', 'tipo_contrato', 'tipo_trabajador', 'subtipo_trabajador',
        'fecha_inicio', 'fecha_fin', 'salario_basico', 'dias_trabajo',
        'cargo', 'departamento', 'sucursal_id', 'alto_riesgo', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'salario_basico' => 'decimal:4',
            'fecha_inicio'   => 'date',
            'fecha_fin'      => 'date',
            'alto_riesgo'    => 'boolean',
            'activo'         => 'boolean',
        ];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }
}
