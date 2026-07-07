<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periodo contable mensual o anual.
 * Estados: abierto → en_revision → cerrado → bloqueado_fiscal
 */
class PeriodoContable extends Model
{
    use HasUuids;
    use Auditable;

    protected $table = 'periodos_contables';

    public const TIPO_MENSUAL = 'mensual';
    public const TIPO_ANUAL = 'anual';

    public const ESTADO_ABIERTO = 'abierto';
    public const ESTADO_EN_REVISION = 'en_revision';
    public const ESTADO_CERRADO = 'cerrado';
    public const ESTADO_BLOQUEADO_FISCAL = 'bloqueado_fiscal';

    protected $fillable = [
        'tipo', 'codigo', 'fecha_inicio', 'fecha_fin', 'año_fiscal', 'mes', 'estado',
        'cerrado_por_id', 'cerrado_at', 'motivo_cierre',
        'reabierto_por_id', 'reabierto_at', 'motivo_reapertura',
        'bloqueado_fiscal_por_id', 'bloqueado_fiscal_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin'    => 'date',
            'cerrado_at'   => 'datetime',
            'reabierto_at' => 'datetime',
            'bloqueado_fiscal_at' => 'datetime',
            'año_fiscal'   => 'integer',
            'mes'          => 'integer',
        ];
    }

    public function auditableActionPrefix(): string
    {
        return 'periodo';
    }

    public function auditableLabel(): string
    {
        return $this->codigo;
    }

    // -----------------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------------

    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class, 'periodo_id');
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Devuelve el periodo mensual que contiene la fecha dada.
     * Si no existe, lo crea en estado abierto (idempotente).
     */
    public static function actual(\DateTimeInterface|string $fecha): self
    {
        $f = $fecha instanceof \DateTimeInterface
            ? \Carbon\CarbonImmutable::instance($fecha)
            : \Carbon\CarbonImmutable::parse($fecha);

        $codigo = sprintf('%04d-%02d', $f->year, $f->month);

        return self::firstOrCreate(
            ['codigo' => $codigo],
            [
                'tipo'         => self::TIPO_MENSUAL,
                'fecha_inicio' => $f->startOfMonth()->toDateString(),
                'fecha_fin'    => $f->endOfMonth()->toDateString(),
                'año_fiscal'   => $f->year,
                'mes'          => $f->month,
                'estado'       => self::ESTADO_ABIERTO,
            ]
        );
    }

    public function scopeAbiertos(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_ABIERTO);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function estaAbierto(): bool
    {
        return $this->estado === self::ESTADO_ABIERTO;
    }

    public function estaCerrado(): bool
    {
        return in_array($this->estado, [self::ESTADO_CERRADO, self::ESTADO_BLOQUEADO_FISCAL], true);
    }

    public function estaBloqueadoFiscalmente(): bool
    {
        return $this->estado === self::ESTADO_BLOQUEADO_FISCAL;
    }
}
