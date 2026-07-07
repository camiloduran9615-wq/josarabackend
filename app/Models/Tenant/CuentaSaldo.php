<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libro Mayor materializado — una fila por (cuenta × periodo × tercero? × centro_costo? × sucursal?).
 *
 * Esta tabla es la fuente autoritativa de saldos para reportes financieros.
 * Es actualizada de forma transaccional por `ActualizarSaldosListener` mediante
 * UPSERT atómico (ON CONFLICT) al disparar los Domain Events:
 *   - AsientoAprobado    → suma movimiento_debito / movimiento_credito
 *   - AsientoAnulado     → resta los aportes originales
 *   - AsientoReversado   → no toca el original (el reverso entra como nuevo AsientoAprobado)
 *
 * Precisión interna `DECIMAL(18,4)`. Las consultas de reportes consumen este modelo
 * directamente sin recalcular sobre `asiento_lineas`.
 *
 * Integridad: `ReconciliarSaldosJob` (nightly) compara este modelo vs SUM(asiento_lineas)
 * y emite `SaldosInconsistenciaDetectada` si Δ > 0.01 COP en cualquier fila.
 *
 * @property string $id
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
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class CuentaSaldo extends Model
{
    use HasUuids;

    protected $table = 'cuenta_saldos';

    protected $fillable = [
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
            'created_at'            => 'immutable_datetime',
            'updated_at'            => 'immutable_datetime',
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
     * @return BelongsTo<PeriodoContable, $this>
     */
    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoContable::class, 'periodo_id');
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'tercero_id');
    }

    /**
     * @return BelongsTo<CentroCosto, $this>
     */
    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDelPeriodo(Builder $query, string $periodoId): Builder
    {
        return $query->where('periodo_id', $periodoId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeCuenta(Builder $query, string $cuentaId): Builder
    {
        return $query->where('cuenta_contable_id', $cuentaId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConMovimiento(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('movimiento_debito', '>', 0)
              ->orWhere('movimiento_credito', '>', 0)
              ->orWhere('saldo_inicial_debito', '>', 0)
              ->orWhere('saldo_inicial_credito', '>', 0);
        });
    }

    // ── Helpers de presentación ────────────────────────────────────────────

    /**
     * Saldo neto del periodo según naturaleza de la cuenta padre.
     * Positivo si la cuenta está "del lado" de su naturaleza; negativo en caso contrario.
     */
    public function saldoFinalNeto(): float
    {
        return (float) $this->saldo_final_debito - (float) $this->saldo_final_credito;
    }

    /** Movimiento neto del periodo (no incluye saldo inicial). */
    public function movimientoNeto(): float
    {
        return (float) $this->movimiento_debito - (float) $this->movimiento_credito;
    }
}
