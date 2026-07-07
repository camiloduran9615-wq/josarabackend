<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Saldos\ReconciliarSaldosJob;
use App\Models\Tenant as TenantCentralModel;
use App\Services\Saldos\ReconciliarSaldosService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Comando: php artisan saldos:reconciliar {tenant?} {--periodo=} {--all} {--sync}
 *
 * Verifica que cuenta_saldos coincide con la suma real de asiento_lineas aprobados.
 * Reporta anomalías por consola y dispara `SaldosInconsistenciaDetectada` si detecta drift.
 *
 * Ejecutado nightly por scheduler (`routes/console.php`) para todos los tenants.
 */
final class SaldosReconciliarCommand extends Command
{
    /** @var string */
    protected $signature = 'saldos:reconciliar
                            {tenant? : UUID del tenant a reconciliar (omitir si --all)}
                            {--periodo= : UUID del periodo (opcional; default = todos)}
                            {--all : Reconcilia todos los tenants activos}
                            {--sync : Ejecuta en este proceso (no encola)}';

    /** @var string */
    protected $description = 'Compara cuenta_saldos vs SUM(asiento_lineas) y reporta drift.';

    public function handle(ReconciliarSaldosService $service): int
    {
        $sync       = (bool) $this->option('sync');
        $all        = (bool) $this->option('all');
        $tenantArg  = $this->argument('tenant');
        $periodoId  = $this->option('periodo');

        if ($all === false && $tenantArg === null) {
            $this->error('Debes indicar un tenant UUID o usar --all.');
            return self::INVALID;
        }
        if ($all && $tenantArg !== null) {
            $this->error('No combines un tenant específico con --all.');
            return self::INVALID;
        }

        $tenantIds = $all
            ? $this->tenantIdsActivos()
            : [trim((string) $tenantArg, "\"' ")];

        if ($tenantIds === []) {
            $this->warn('No hay tenants para procesar.');
            return self::SUCCESS;
        }

        $totalAnomalias = 0;
        $errores        = 0;

        foreach ($tenantIds as $tenantId) {
            try {
                if ($sync) {
                    $this->info("→ Reconciliando tenant {$tenantId}" . ($periodoId !== null ? " (periodo={$periodoId})" : ''));
                    $r = $service->reconciliar($tenantId, $periodoId !== null ? (string) $periodoId : null);

                    if ($r->estaLimpio()) {
                        $this->info(sprintf(
                            '  ✓ limpia (%d filas comparadas, %ds)',
                            $r->filasComparadas,
                            $r->duracionSegundos(),
                        ));
                    } else {
                        $this->warn(sprintf(
                            '  ✗ DRIFT: %d anomalías, ΔD_total=%s ΔC_total=%s',
                            $r->anomaliasCount,
                            $r->deltaDebitoTotal,
                            $r->deltaCreditoTotal,
                        ));
                        $this->renderAnomaliasTabla($r->anomalias);
                        $totalAnomalias += $r->anomaliasCount;
                    }
                } else {
                    ReconciliarSaldosJob::dispatch($tenantId, $periodoId !== null ? (string) $periodoId : null);
                    $this->info("→ Encolado para tenant {$tenantId}" . ($periodoId !== null ? " (periodo={$periodoId})" : ''));
                }
            } catch (Throwable $e) {
                $errores++;
                $this->error("✗ Falló reconciliación para tenant {$tenantId}: {$e->getMessage()}");
            }
        }

        if ($errores > 0) {
            return self::FAILURE;
        }
        // En modo sync, retornar FAILURE si hubo drift (útil para CI / scripts shell)
        return ($sync && $totalAnomalias > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<\App\Services\Saldos\DTOs\AnomaliaSaldoDto>  $anomalias
     */
    private function renderAnomaliasTabla(array $anomalias): void
    {
        $rows = array_map(
            static fn ($a): array => [
                $a->cuentaCodigo,
                substr($a->periodoId, 0, 8) . '…',
                $a->terceroId !== null ? substr($a->terceroId, 0, 8) . '…' : '—',
                $a->movimientoDebitoMaterializado,
                $a->movimientoDebitoReal,
                $a->deltaDebito,
                $a->movimientoCreditoMaterializado,
                $a->movimientoCreditoReal,
                $a->deltaCredito,
            ],
            array_slice($anomalias, 0, 20), // truncar tabla a 20 filas
        );

        $this->table(
            ['Cuenta', 'Periodo', 'Tercero', 'D mat', 'D real', 'ΔD', 'C mat', 'C real', 'ΔC'],
            $rows,
        );

        if (count($anomalias) > 20) {
            $this->line('  … ' . (count($anomalias) - 20) . ' anomalías adicionales omitidas');
        }
    }

    /**
     * @return list<string>
     */
    private function tenantIdsActivos(): array
    {
        /** @var list<string> $ids */
        $ids = TenantCentralModel::query()
            ->where('activo', true)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        return $ids;
    }
}
