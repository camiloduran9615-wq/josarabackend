<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Periodo\PeriodoCerrado;
use App\Models\Tenant\PeriodoContable;
use App\Services\AuditLogService;

class RecordAuditOnPeriodoCerrado
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function handle(PeriodoCerrado $event): void
    {
        $action = $event->periodo->tipo === PeriodoContable::TIPO_ANUAL
            ? 'periodo.closed_annual'
            : 'periodo.closed_monthly';

        $criticidad = $event->periodo->tipo === PeriodoContable::TIPO_ANUAL
            ? AuditLogService::CRITICIDAD_CRITICAL
            : AuditLogService::CRITICIDAD_WARNING;

        $this->logger->record(
            action: $action,
            criticidad: $criticidad,
            auditable: $event->periodo,
            motivo: $event->motivo,
            metadata: ['closer_id' => $event->closer->id],
        );
    }
}
