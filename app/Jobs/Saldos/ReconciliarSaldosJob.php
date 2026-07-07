<?php

declare(strict_types=1);

namespace App\Jobs\Saldos;

use App\Services\Saldos\ReconciliarSaldosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Job que ejecuta `ReconciliarSaldosService` para un tenant.
 *
 * Despachado:
 *  - Nightly por scheduler @02:00 hora Colombia (uno por tenant activo)
 *  - On-demand desde `php artisan saldos:reconciliar`
 *
 * Tries=2 — si falla por timeout BD, vale la pena un retry. Si falla por bug
 * (RuntimeException sin recuperación), no insistimos.
 *
 * Si detecta drift, el SERVICE despacha `SaldosInconsistenciaDetectada` —
 * el listener `NotificarN8nListener` (async) propaga al webhook del tenant.
 */
final class ReconciliarSaldosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** Timeout amplio: la query con FULL OUTER JOIN puede tardar en tenants con millones de líneas. */
    public int $timeout = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $periodoId = null,
    ) {
        $this->onQueue('saldos-reconciliacion');
    }

    public function handle(ReconciliarSaldosService $service, LoggerInterface $logger): void
    {
        $logger->info('Reconciliación de saldos iniciada', [
            'tenant_id'  => $this->tenantId,
            'periodo_id' => $this->periodoId,
            'attempt'    => $this->attempts(),
        ]);

        $resultado = $service->reconciliar($this->tenantId, $this->periodoId);

        $logger->info('Reconciliación de saldos finalizada', [
            'tenant_id'         => $this->tenantId,
            'filas_comparadas'  => $resultado->filasComparadas,
            'anomalias'         => $resultado->anomaliasCount,
            'limpia'            => $resultado->estaLimpio(),
            'duracion_segundos' => $resultado->duracionSegundos(),
        ]);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [120, 600]; // 2min, 10min
    }
}
