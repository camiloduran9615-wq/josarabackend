<?php

declare(strict_types=1);

namespace App\Jobs\Saldos;

use App\Services\Saldos\BackfillSaldosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Job de cola que ejecuta el backfill de saldos para un tenant.
 *
 * Para tenants grandes el backfill puede tardar minutos u horas — la cola permite:
 *   - Lanzarlo desde el endpoint de migración sin bloquear el HTTP request
 *   - Reintentos automáticos ante fallos transitorios (BD lock, network blip)
 *   - Visibility en Horizon UI
 *
 * El SERVICE de fondo (`BackfillSaldosService`) ya es reanudable via checkpoint,
 * así que un retry tras fallo continúa desde donde quedó.
 */
final class BackfillSaldosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Máximo de intentos antes de marcar como failed. */
    public int $tries = 3;

    /** Timeout amplio: tenants grandes pueden tardar minutos por chunk. */
    public int $timeout = 3600; // 1 hora

    public function __construct(
        public readonly string $tenantId,
        public readonly bool $fresh = false,
    ) {
        // Cola dedicada para no saturar la cola default con jobs largos.
        $this->onQueue('saldos-backfill');
    }

    public function handle(BackfillSaldosService $service, LoggerInterface $logger): void
    {
        $logger->info('Backfill saldos iniciado', [
            'tenant_id' => $this->tenantId,
            'fresh'     => $this->fresh,
            'attempt'   => $this->attempts(),
        ]);

        $resultado = $service->ejecutar($this->tenantId, $this->fresh);

        $logger->info('Backfill saldos completado', [
            'tenant_id'  => $this->tenantId,
            'procesados' => $resultado['processed'],
            'omitidos'   => $resultado['skipped'],
        ]);
    }

    /**
     * Backoff exponencial entre reintentos: 1min, 5min, 15min.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Identificador único para evitar duplicar jobs del mismo tenant en cola.
     */
    public function uniqueId(): string
    {
        return "backfill-saldos:{$this->tenantId}";
    }
}
