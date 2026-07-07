<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Periodo\PeriodoReabierto;
use App\Services\AuditLogService;

class RecordAuditOnPeriodoReabierto
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function handle(PeriodoReabierto $event): void
    {
        $this->logger->record(
            action: 'periodo.reopened',
            criticidad: AuditLogService::CRITICIDAD_CRITICAL,
            auditable: $event->periodo,
            motivo: $event->motivo,
            metadata: [
                'contador_id' => $event->contador->id,
                'admin_id'    => $event->admin->id,
            ],
        );
    }
}
