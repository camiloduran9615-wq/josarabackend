<?php

declare(strict_types=1);

namespace App\Events\Impuesto;

use App\Domain\Events\DomainEvent;
use App\Models\Tenant\Impuesto;
use App\Models\User;

/**
 * El tenant creó un nuevo impuesto custom (no sistema).
 *
 * Auditado como `config.tax_rate_changed` con criticidad warning (Contador §6.1).
 */
final class ImpuestoCreado extends DomainEvent
{
    public function __construct(
        public readonly Impuesto $impuesto,
        public readonly User $creadoPor,
    ) {
        parent::__construct();
    }
}
