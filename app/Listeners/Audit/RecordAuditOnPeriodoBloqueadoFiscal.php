<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Periodo\PeriodoBloqueadoFiscal;
use App\Services\AuditLogService;

class RecordAuditOnPeriodoBloqueadoFiscal
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function handle(PeriodoBloqueadoFiscal $event): void
    {
        $this->logger->record(
            action: 'periodo.locked_fiscal',
            criticidad: AuditLogService::CRITICIDAD_CRITICAL,
            auditable: $event->periodo,
            metadata: ['admin_id' => $event->admin->id],
        );
    }
}
