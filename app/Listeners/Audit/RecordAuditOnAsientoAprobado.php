<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Asiento\AsientoAprobado;
use App\Services\AuditLogService;

class RecordAuditOnAsientoAprobado
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function handle(AsientoAprobado $event): void
    {
        $this->logger->record(
            action: 'asiento.approved',
            criticidad: AuditLogService::CRITICIDAD_WARNING,
            auditable: $event->asiento,
            newValues: [
                'numero'     => $event->asiento->numero,
                'estado'     => $event->asiento->estado,
                'total'      => $event->asiento->totalDebito(),
                'approver'   => $event->approver->id,
            ],
        );
    }
}
