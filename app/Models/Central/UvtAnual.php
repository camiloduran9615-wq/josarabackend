<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidad de Valor Tributario por año fiscal (DIAN).
 *
 * Vive en BD central. Lectura compartida entre tenants.
 * Toda lógica tributaria que necesite "valor UVT del año N" debe consultar
 * vía `UvtAnualRepository::vigente()` o `::deAnio(int $anio)` — NUNCA hardcode.
 *
 * @property int         $anio
 * @property string      $valor_cop            Stored as numeric(10,2) en BD; cast a string para preservar precisión
 * @property string      $resolucion_dian
 * @property \Carbon\CarbonImmutable $vigencia_desde
 * @property \Carbon\CarbonImmutable|null $vigencia_hasta
 * @property \Carbon\CarbonImmutable $created_at
 */
class UvtAnual extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'uvt_anual';

    protected $primaryKey = 'anio';

    public $incrementing = false;

    protected $keyType = 'int';

    public const UPDATED_AT = null; // solo created_at en la tabla

    protected $fillable = [
        'anio',
        'valor_cop',
        'resolucion_dian',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    protected function casts(): array
    {
        return [
            'anio'           => 'integer',
            'valor_cop'      => 'decimal:2',
            'vigencia_desde' => 'immutable_date',
            'vigencia_hasta' => 'immutable_date',
            'created_at'     => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVigentes(Builder $query, ?\DateTimeInterface $fecha = null): Builder
    {
        $fecha ??= new \DateTimeImmutable();

        return $query
            ->where('vigencia_desde', '<=', $fecha)
            ->where(function (Builder $q) use ($fecha): void {
                $q->whereNull('vigencia_hasta')
                  ->orWhere('vigencia_hasta', '>=', $fecha);
            });
    }
}
