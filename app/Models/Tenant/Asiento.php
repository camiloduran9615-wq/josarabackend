<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asiento contable. Encabezado del Libro Diario.
 * Cumple partida doble (validación en FormRequest + CHECK en BD).
 *
 * Lifecycle: borrador → aprobado → anulado | reversado
 * (ver §2 de reglas_asientos_auditoria.md)
 */
class Asiento extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Auditable;

    public const ESTADO_BORRADOR = 'borrador';
    public const ESTADO_APROBADO = 'aprobado';
    public const ESTADO_ANULADO = 'anulado';
    public const ESTADO_REVERSADO = 'reversado';

    public const TIPO_NORMAL = 'normal';
    public const TIPO_REVERSO = 'reverso';
    public const TIPO_CIERRE = 'cierre';
    public const TIPO_APERTURA = 'apertura';

    /**
     * Campos asignables masivamente (SOLO los que el usuario puede enviar directamente).
     * Los campos de ciclo de vida (estado, numero, approved_by_id, etc.) se escriben
     * únicamente a través de los métodos del AsientoService para garantizar
     * que las transiciones de estado pasen por las reglas de negocio y la auditoría.
     *
     * SEGURIDAD: No añadir 'estado', 'numero', 'approved_by_id', 'voided_by_id',
     * 'approved_at', 'voided_at', 'tipo_movimiento' aquí. Ver AsientoService.
     */
    protected $fillable = [
        // Cabecera editable por el usuario
        'tipo_comprobante',
        'fecha',
        'periodo_id',
        'sucursal_id',
        'centro_costo_id',
        'comprobante',        // legacy
        'numero_documento',
        'glosa',
        'descripcion',
        'soportes_urls',
        // Actores de autoría (escritos sólo por el service)
        'created_by_id',
        'last_modified_by_id',
        // Origen polimórfico (escrito sólo por el service/contabilizador)
        'origen_type',
        'origen_id',
        // ── Campos de ciclo de vida — NO deben estar en fillable ──────────
        // 'estado'           → usar AsientoService
        // 'tipo_movimiento'  → usar AsientoService
        // 'numero'           → usar ConsecutivoAsientoService
        // 'año_fiscal'       → derivado de fecha
        // 'approved_by_id'   → usar AsientoService::aprobar()
        // 'approved_at'      → usar AsientoService::aprobar()
        // 'voided_by_id'     → usar AsientoService::anular()
        // 'voided_at'        → usar AsientoService::anular()
        // 'motivo_anulacion' → usar AsientoService::anular()
        // 'motivo_reverso'   → usar AsientoService::reversar()
        // 'origen_reverso_id'→ usar AsientoService::reversar()
        // 'reversado_por_id' → usar AsientoService::reversar()
    ];

    /**
     * Campos que el service escribe directamente con update([...]) o create([...]).
     * Se listan aquí para documentar su existencia; NO son fillable a propósito.
     */
    public const LIFECYCLE_FIELDS = [
        'estado', 'tipo_movimiento', 'numero', 'año_fiscal',
        'approved_by_id', 'approved_at',
        'voided_by_id', 'voided_at', 'motivo_anulacion',
        'motivo_reverso', 'origen_reverso_id', 'reversado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha'         => 'date',
            'approved_at'   => 'datetime',
            'voided_at'     => 'datetime',
            'soportes_urls' => 'array',
            'año_fiscal'    => 'integer',
        ];
    }

    public function auditableActionPrefix(): string
    {
        return 'asiento';
    }

    public function auditableLabel(): string
    {
        return $this->numero ?? ($this->id ?? '');
    }

    // -----------------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------------

    public function lineas(): HasMany
    {
        return $this->hasMany(AsientoLinea::class, 'asiento_id');
    }

    /** Alias legacy. */
    public function items(): HasMany
    {
        return $this->lineas();
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoContable::class, 'periodo_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    public function origenReverso(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origen_reverso_id');
    }

    public function reversadoPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversado_por_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function lastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_modified_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    public function scopeBorradores(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_BORRADOR);
    }

    public function scopeAprobados(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_APROBADO);
    }

    public function scopeDelPeriodo(Builder $q, string $periodoId): Builder
    {
        return $q->where('periodo_id', $periodoId);
    }

    // -----------------------------------------------------------------------
    // Helpers de estado
    // -----------------------------------------------------------------------

    public function esBorrador(): bool
    {
        return $this->estado === self::ESTADO_BORRADOR;
    }

    public function esAprobado(): bool
    {
        return $this->estado === self::ESTADO_APROBADO;
    }

    public function esAnulado(): bool
    {
        return $this->estado === self::ESTADO_ANULADO;
    }

    // -----------------------------------------------------------------------
    // Cálculos de partida doble
    // -----------------------------------------------------------------------

    public function totalDebito(): float
    {
        return (float) $this->lineas->sum(fn ($l) => (float) $l->debito);
    }

    public function totalCredito(): float
    {
        return (float) $this->lineas->sum(fn ($l) => (float) $l->credito);
    }

    public function balanceado(): bool
    {
        return abs($this->totalDebito() - $this->totalCredito()) <= 0.01;
    }
}
