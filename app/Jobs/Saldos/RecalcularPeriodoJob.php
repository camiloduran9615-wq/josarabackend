<?php

declare(strict_types=1);

namespace App\Jobs\Saldos;

use App\Services\Saldos\RecalcularPeriodoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Job de recovery manual: recalcula cuenta_saldos para un periodo específico.
 *
 * Dispatch típico (desde artisan):
 *   php artisan saldos:recalcular {tenant_id} {periodo_id}
 *   php artisan saldos:recalcular {tenant_id} {periodo_id} --sync
 *
 * A diferencia de BackfillSaldosJob (que procesa todos los asientos del tenant
 * en chunks con checkpoint), este job es más rápido y preciso:
 *  - Opera dentro de una única transacción DB (atomic).
 *  - Solo toca las filas de `cuenta_saldos` del periodo indicado.
 *  - Ejecuta todos los asientos del periodo en memoria (sin paginación por cursor).
 *
 * Si el periodo tiene millones de líneas, preferir BackfillSaldosJob con --fresh
 * sobre el tenant completo.
 */
final class RecalcularPeriodoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** Suficiente para periodos grandes; transacción única = no reanudable. */
    public int $timeout = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $periodoId,
    ) {
        $this->onQueue('saldos-backfill');
    }

    public function handle(RecalcularPeriodoService $service, LoggerInterface $logger): void
    {
        $resultado = $service->recalcular($this->tenantId, $this->periodoId);

        $logger->info('RecalcularPeriodoJob completado', [
            'tenant_id'           => $this->tenantId,
            'periodo_id'          => $this->periodoId,
            'asientos_procesados' => $resultado['asientos_procesados'],
            'lineas_procesadas'   => $resultado['lineas_procesadas'],
        ]);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function uniqueId(): string
    {
        return "recalcular-periodo:{$this->tenantId}:{$this->periodoId}";
    }
}
