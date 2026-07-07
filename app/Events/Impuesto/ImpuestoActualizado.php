<?php

declare(strict_types=1);

namespace App\Events\Impuesto;

use App\Domain\Events\DomainEvent;
use App\Models\Tenant\Impuesto;
use App\Models\User;

/**
 * El tenant modificó un impuesto custom (impuestos sistema=true son inmutables).
 *
 * Cambios en `tarifa_porcentaje`, `base_minima_uvt`, `vigencia_*`, `activa` son
 * críticos: afectan cálculos de futuras facturas/asientos. Auditado con
 * old_values/new_values completos.
 */
final class ImpuestoActualizado extends DomainEvent
{
    /**
     * @param  array<string,mixed>  $oldValues
     * @param  array<string,mixed>  $newValues
     */
    public function __construct(
        public readonly Impuesto $impuesto,
        public readonly User $actualizadoPor,
        public readonly array $oldValues,
        public readonly array $newValues,
    ) {
        parent::__construct();
    }
}
