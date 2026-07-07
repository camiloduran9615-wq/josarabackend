<?php

declare(strict_types=1);

namespace App\Events\CierreAnual;

use App\Domain\Events\DomainEvent;
use App\Models\Tenant\Asiento;
use App\Models\User;

/**
 * El contador ejecutó el cierre anual del año fiscal indicado.
 *
 * Lleva los asientos automáticos generados:
 *   - 0: Cancelación de cuentas de resultado (clases 4,5,6,7 → 5905)
 *   - 1: Traslado 5905 → 3605/3610 (utilidad / pérdida)
 *   - 2: Asiento de Apertura del año siguiente (opcional, según política PM)
 *
 * Listeners disparados:
 *   - AuditarCierreAnualListener (crítico, persiste en AuditLog central)
 *   - InvalidarCacheReportesListener (purge total por tenant)
 *   - NotificarN8nListener (email a contador + admin con resumen)
 */
final class CierreAnualEjecutado extends DomainEvent
{
    /**
     * @param  list<Asiento>  $asientos
     */
    public function __construct(
        public readonly int $anio,
        public readonly array $asientos,
        public readonly User $ejecutadoPor,
        public readonly string $resultado,    // 'utilidad' | 'perdida' | 'equilibrio'
        public readonly string $montoResultado, // string DECIMAL(18,4)
    ) {
        parent::__construct();
    }
}
