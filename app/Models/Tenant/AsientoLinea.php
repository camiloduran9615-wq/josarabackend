<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de partida doble. Vive sobre la tabla `asiento_items`
 * (nombre legacy) — se accede como "linea" en la API y vocabulario contable.
 *
 * Cada línea es exclusivamente débito o crédito (CHECK constraint en PG).
 */
class AsientoLinea extends Model
{
    use HasUuids;

    protected $table = 'asiento_items';

    protected $fillable = [
        'asiento_id',
        'cuenta_id',
        'tercero_id',
        'centro_costo_id',
        'debito',
        'credito',
        'descripcion_item',
        'documento_referencia',
    ];

    protected function casts(): array
    {
        return [
            'debito'  => 'decimal:4',
            'credito' => 'decimal:4',
        ];
    }

    // -----------------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------------

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_id');
    }

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function esDebito(): bool
    {
        return (float) $this->debito > 0;
    }

    public function esCredito(): bool
    {
        return (float) $this->credito > 0;
    }
}
