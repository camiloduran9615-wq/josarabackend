<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Snapshot inmutable de saldos al cerrar un periodo (mensual o anual).
 *
 * Append-only enforcement:
 *   1) Trigger PostgreSQL `trg_csh_protect` rechaza UPDATE/DELETE a nivel BD.
 *   2) Este modelo lanza LogicException si se intenta `update()`/`delete()` desde Eloquent.
 *   3) (Recomendado infra) REVOKE UPDATE,DELETE al rol de aplicación `saas_app`.
 *
 * Cumple Resolución DIAN 000042/2020 y Código de Comercio art. 28
 * (10 años de conservación; política SaaS: 15 años).
 *
 * @property string $id
 * @property string $cuenta_saldo_id          UUID del cuenta_saldos al momento del snapshot
 * @property string $cuenta_contable_id
 * @property string $periodo_id
 * @property string|null $tercero_id
 * @property string|null $centro_costo_id
 * @property string|null $sucursal_id
 * @property string $saldo_inicial_debito
 * @property string $saldo_inicial_credito
 * @property string $movimiento_debito
 * @property string $movimiento_credito
 * @property string $saldo_final_debito
 * @property string $saldo_final_credito
 * @property \Carbon\CarbonImmutable $cerrado_at
 * @property string $cerrado_por_user_id
 * @property string $hash_snapshot             SHA-256 hex del registro al cerrar
 * @property string $periodo_codigo            'YYYY-MM' o 'YYYY-FY'
 * @property \Carbon\CarbonImmutable $created_at
 */
class CuentaSaldoHistoricoCierre extends Model
{
    use HasUuids;

    protected $table = 'cuenta_saldos_historicos_cierre';

    /** Solo created_at — la tabla no tiene updated_at (append-only). */
    public const UPDATED_AT = null;

    protected $fillable = [
        'cuenta_saldo_id',
        'cuenta_contable_id',
        'periodo_id',
        'tercero_id',
        'centro_costo_id',
        'sucursal_id',
        'saldo_inicial_debito',
        'saldo_inicial_credito',
        'movimiento_debito',
        'movimiento_credito',
        'saldo_final_debito',
        'saldo_final_credito',
        'cerrado_at',
        'cerrado_por_user_id',
        'hash_snapshot',
        'periodo_codigo',
    ];

    protected function casts(): array
    {
        return [
            'saldo_inicial_debito'  => 'decimal:4',
            'saldo_inicial_credito' => 'decimal:4',
            'movimiento_debito'     => 'decimal:4',
            'movimiento_credito'    => 'decimal:4',
            'saldo_final_debito'    => 'decimal:4',
            'saldo_final_credito'   => 'decimal:4',
            'cerrado_at'            => 'immutable_datetime',
            'created_at'            => 'immutable_datetime',
        ];
    }

    // ── Append-only enforcement a nivel aplicación ─────────────────────────

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException(
            'cuenta_saldos_historicos_cierre es append-only: UPDATE rechazado por política contable.'
        );
    }

    public function delete(): bool
    {
        throw new LogicException(
            'cuenta_saldos_historicos_cierre es append-only: DELETE rechazado por política contable.'
        );
    }

    // ── Relaciones ──────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<CuentaContable, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }

    /**
     * @return BelongsTo<PeriodoContable, $this>
     */
    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoContable::class, 'periodo_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDelPeriodoCodigo(Builder $query, string $codigo): Builder
    {
        return $query->where('periodo_codigo', $codigo);
    }
}
