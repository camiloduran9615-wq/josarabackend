<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Support\Bc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catálogo parametrizable de impuestos por tenant.
 *
 * Tipos (CHECK BD):
 *   iva            — IVA generado (ventas) o descontable (compras)
 *   retefuente     — Retención en la fuente (renta)
 *   reteiva        — Retención de IVA
 *   reteica        — Retención de ICA
 *   autorretencion — Auto-retención de renta (Decreto 2201/2016)
 *
 * `sistema = true` para tarifas DIAN preinstaladas vía seed. Estas NO son editables
 * ni eliminables por el tenant (enforced en `ImpuestoPolicy`).
 *
 * Vigencia inclusiva:
 *   activa AND vigencia_desde <= fecha AND (vigencia_hasta IS NULL OR vigencia_hasta >= fecha).
 *
 * @property string $id
 * @property string $tipo
 * @property string $codigo
 * @property string|null $codigo_dian_ubl
 * @property string|null $concepto_dian
 * @property string $nombre
 * @property string $tarifa_porcentaje      DECIMAL(7,4)
 * @property string|null $base_minima_uvt
 * @property bool   $aplica_compras
 * @property bool   $aplica_ventas
 * @property string $cuenta_contable_id
 * @property string|null $cuenta_contrapartida_id
 * @property string|null $actividad_ciiu
 * @property \Carbon\CarbonImmutable $vigencia_desde
 * @property \Carbon\CarbonImmutable|null $vigencia_hasta
 * @property bool   $activa
 * @property string|null $descripcion
 * @property array<string,mixed>|null $metadata
 * @property bool   $sistema
 * @property string|null $created_by_user_id
 */
class Impuesto extends Model
{
    use HasUuids;

    public const TIPO_IVA            = 'iva';
    public const TIPO_RETEFUENTE     = 'retefuente';
    public const TIPO_RETEIVA        = 'reteiva';
    public const TIPO_RETEICA        = 'reteica';
    public const TIPO_AUTORRETENCION = 'autorretencion';

    public const TIPOS_VALIDOS = [
        self::TIPO_IVA,
        self::TIPO_RETEFUENTE,
        self::TIPO_RETEIVA,
        self::TIPO_RETEICA,
        self::TIPO_AUTORRETENCION,
    ];

    protected $table = 'impuestos';

    protected $fillable = [
        'tipo',
        'codigo',
        'codigo_dian_ubl',
        'concepto_dian',
        'nombre',
        'tarifa_porcentaje',
        'base_minima_uvt',
        'aplica_compras',
        'aplica_ventas',
        'cuenta_contable_id',
        'cuenta_contrapartida_id',
        'actividad_ciiu',
        'vigencia_desde',
        'vigencia_hasta',
        'activa',
        'descripcion',
        'metadata',
        'sistema',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tarifa_porcentaje' => 'decimal:4',
            'base_minima_uvt'   => 'decimal:2',
            'aplica_compras'    => 'boolean',
            'aplica_ventas'     => 'boolean',
            'vigencia_desde'    => 'immutable_date',
            'vigencia_hasta'    => 'immutable_date',
            'activa'            => 'boolean',
            'metadata'          => 'array',
            'sistema'           => 'boolean',
            'created_at'        => 'immutable_datetime',
            'updated_at'        => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CuentaContable, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }

    /**
     * @return BelongsTo<CuentaContable, $this>
     */
    public function cuentaContrapartida(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contrapartida_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /**
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDelTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAplicaCompras(Builder $query): Builder
    {
        return $query->where('aplica_compras', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAplicaVentas(Builder $query): Builder
    {
        return $query->where('aplica_ventas', true);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Calcula el valor del impuesto sobre una base dada, en COP.
     * Para tipos `retefuente|reteica`, la tarifa se aplica sobre la base bruta.
     * Para `iva`, la tarifa se aplica sobre la base gravable (no incluye IVA).
     * Para `reteiva`, la tarifa se aplica sobre el VALOR DEL IVA (no sobre base).
     *
     * Devuelve string con 4 decimales para preservar precisión.
     */
    public function calcularSobre(string|float|int $base): string
    {
        return Bc::porcentaje($base, (string) $this->tarifa_porcentaje);
    }

    public function vigenteEn(\DateTimeInterface $fecha): bool
    {
        if (! $this->activa) {
            return false;
        }
        $desde = $this->vigencia_desde;
        if ($desde > $fecha) {
            return false;
        }
        return $this->vigencia_hasta === null || $this->vigencia_hasta >= $fecha;
    }
}
