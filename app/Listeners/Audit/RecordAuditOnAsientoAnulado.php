<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Asiento\AsientoAnulado;
use App\Services\AuditLogService;

class RecordAuditOnAsientoAnulado
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function handle(AsientoAnulado $event): void
    {
        $this->logger->record(
            action: 'asiento.voided',
            criticidad: AuditLogService::CRITICIDAD_CRITICAL,
            auditable: $event->asiento,
            motivo: $event->motivo,
            metadata: [
                'voided_by_id' => $event->voider->id,
                'numero'       => $event->asiento->numero,
            ],
        );
    }
}
