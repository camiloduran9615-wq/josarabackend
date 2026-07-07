<?php

declare(strict_types=1);

namespace App\Events\Saldos;

use App\Domain\Events\DomainEvent;
use App\Services\Saldos\DTOs\ReconciliacionResultDto;

/**
 * El job nocturno `ReconciliarSaldosJob` detectó drift entre `cuenta_saldos`
 * y la suma real de `asiento_lineas` aprobadas.
 *
 * Criticidad **critical** — implica que un reporte financiero puede estar mintiendo.
 *
 * Listeners disparados:
 *   - AuditarSaldosInconsistenciaListener (AuditLog crítico, hash chain)
 *   - NotificarN8nListener (webhook prioritario al Admin del tenant + email a soporte SaaS)
 *
 * El listener NO repara automáticamente. El admin debe ejecutar `php artisan saldos:recalcular`
 * tras investigar la causa raíz.
 */
final class SaldosInconsistenciaDetectada extends DomainEvent
{
    public function __construct(
        public readonly ReconciliacionResultDto $resultado,
    ) {
        parent::__construct();
    }
}
