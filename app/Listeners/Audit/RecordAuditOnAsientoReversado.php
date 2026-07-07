<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Asiento\AsientoReversado;
use App\Services\AuditLogService;

class RecordAuditOnAsientoReversado
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function handle(AsientoReversado $event): void
    {
        $this->logger->record(
            action: 'asiento.reversed',
            criticidad: AuditLogService::CRITICIDAD_CRITICAL,
            auditable: $event->original,
            motivo: $event->motivo,
            metadata: [
                'reverso_id'    => $event->reverso->id,
                'reverser_id'   => $event->reverser->id,
                'numero_original' => $event->original->numero,
                'numero_reverso'  => $event->reverso->numero,
            ],
        );
    }
}
