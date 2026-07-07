<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empleado extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'tipo_documento', 'numero_documento',
        'primer_nombre', 'segundo_nombre',
        'primer_apellido', 'segundo_apellido',
        'email', 'telefono',
        'banco', 'tipo_cuenta', 'numero_cuenta',
        'tercero_id', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->primer_nombre} {$this->segundo_nombre} {$this->primer_apellido} {$this->segundo_apellido}");
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoLaboral::class);
    }

    public function contratoActivo(): ?ContratoLaboral
    {
        return $this->contratos()
            ->where('activo', true)
            ->whereNull('fecha_fin')
            ->orWhere('fecha_fin', '>=', now()->toDateString())
            ->orderByDesc('fecha_inicio')
            ->first();
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionNomina::class);
    }
}
