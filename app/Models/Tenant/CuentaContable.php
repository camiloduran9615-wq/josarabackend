<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan Único de Cuentas — nodo del árbol contable.
 *
 * Jerarquía PUC colombiano (Decreto 2649/93 + NIIF PYMES 2420/2015):
 *   clase     (1 dígito)  → solo agrupación
 *   grupo     (2 dígitos) → solo agrupación
 *   cuenta    (4 dígitos) → solo agrupación
 *   subcuenta (6 dígitos) → acepta movimientos (nivel operativo estándar en Colombia)
 *   auxiliar  (8+ dígitos)→ acepta movimientos (detalle por tercero/proyecto)
 *
 * Regla: acepta_movimientos = true SOLO en subcuenta/auxiliar.
 * Las clases, grupos y cuentas son solo para informes y totalización.
 *
 * NOTA DE CAMPOS DUPLICADOS:
 *   - exige_tercero       (migración original 2026-05-02)
 *   - requiere_tercero    (migración EPIC-002 2026-05-07)
 *   Ambos están en BD. Este modelo escribe en `exige_tercero` (canónico).
 *   `requiere_tercero` se mantiene por compatibilidad y se sincroniza via
 *   boot(). Se eliminará en una futura migración de consolidación.
 *
 * @property string      $id
 * @property string      $codigo
 * @property string      $nombre
 * @property string      $naturaleza         debito|credito
 * @property string      $nivel              clase|grupo|cuenta|subcuenta|auxiliar
 * @property string|null $parent_id
 * @property bool        $acepta_movimientos Solo subcuenta/auxiliar
 * @property bool        $exige_tercero      Requiere tercero en línea de asiento
 * @property bool        $exige_centro_costo Requiere centro de costo
 * @property bool        $exige_base_impuesto Para retenciones/IVA
 * @property bool        $activo
 * @property string      $tipo_cuenta        agrupacion|movimiento
 */
class CuentaContable extends Model
{
    use HasUuids;

    protected $table = 'cuentas_contables';

    protected $fillable = [
        'codigo',
        'nombre',
        'naturaleza',
        'nivel',
        'parent_id',
        'acepta_movimientos',
        'exige_tercero',
        'exige_centro_costo',
        'exige_base_impuesto',
        'activo',
        // Campos EPIC-LMB-001
        'clase',
        'clasificacion_balance',
        'clasificacion_pyg',
        'nif_referencia',
        'sistema',
        'editable',
        // Campos EPIC-002 (se sincronizan con los canónicos en boot)
        'requiere_tercero',
        'requiere_centro_costo',
        'tipo_cuenta',
    ];

    protected function casts(): array
    {
        return [
            'acepta_movimientos'    => 'boolean',
            'exige_tercero'         => 'boolean',
            'exige_centro_costo'    => 'boolean',
            'exige_base_impuesto'   => 'boolean',
            'activo'                => 'boolean',
            'sistema'               => 'boolean',
            'editable'              => 'boolean',
            'clase'                 => 'integer',
            'requiere_tercero'      => 'boolean',
            'requiere_centro_costo' => 'boolean',
        ];
    }

    /**
     * Sincroniza los campos duplicados al guardar.
     * requiere_tercero = exige_tercero (ambos significan lo mismo).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $cuenta): void {
            // Sincronizar alias duplicados (usar ?? false por si el campo no se pasó)
            $cuenta->requiere_tercero      = (bool) ($cuenta->exige_tercero       ?? false);
            $cuenta->requiere_centro_costo = (bool) ($cuenta->exige_centro_costo  ?? false);

            // tipo_cuenta coherente con acepta_movimientos
            $cuenta->tipo_cuenta = ($cuenta->acepta_movimientos ?? false) ? 'movimiento' : 'agrupacion';
        });
    }

    // ── Relaciones ─────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('codigo');
    }

    public function descendientes(): HasMany
    {
        return $this->children()->with('descendientes');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /** Solo cuentas que pueden recibir líneas de asiento */
    public function scopeOperativas($query)
    {
        return $query->where('acepta_movimientos', true)->where('activo', true);
    }

    /** Búsqueda por código o nombre */
    public function scopeBuscar($query, string $term)
    {
        return $query->where(function ($q) use ($term): void {
            $q->where('codigo', 'like', "{$term}%")
              ->orWhere('nombre', 'ilike', "%{$term}%");
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Código del padre implícito según longitud (sin BD lookup). */
    public function codigoPadreImplicito(): ?string
    {
        $len = strlen($this->codigo);
        $parentLen = match ($len) {
            2 => 1, 4 => 2, 6 => 4, 8 => 6,
            default => null,
        };
        return $parentLen ? substr($this->codigo, 0, $parentLen) : null;
    }

    public function esOperativa(): bool
    {
        return $this->acepta_movimientos && $this->activo;
    }

    public function esDebito(): bool
    {
        return $this->naturaleza === 'debito';
    }
}
