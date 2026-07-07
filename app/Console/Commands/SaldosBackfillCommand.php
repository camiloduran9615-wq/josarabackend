<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Saldos\BackfillSaldosJob;
use App\Models\Tenant as TenantCentralModel;
use App\Services\Saldos\BackfillSaldosService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Comando: php artisan saldos:backfill {tenant?} {--fresh} {--sync} {--all}
 *
 * Ejemplos:
 *   php artisan saldos:backfill 0a28a442-...                  # un tenant, vía cola
 *   php artisan saldos:backfill 0a28a442-... --sync          # ejecuta en el proceso actual
 *   php artisan saldos:backfill 0a28a442-... --fresh         # trunca y reconstruye desde cero
 *   php artisan saldos:backfill --all                         # encola backfill para todos los tenants activos
 *   php artisan saldos:backfill --all --sync                  # mismo, pero secuencial sin cola
 *
 * Operaciones:
 *   - Default: encola un BackfillSaldosJob en la cola `saldos-backfill`.
 *   - --sync : ejecuta el service directamente en este proceso (útil para deploy / dev).
 *   - --fresh: trunca cuenta_saldos del tenant antes de reconstruir (use con precaución).
 *   - --all  : itera todos los tenants activos.
 */
final class SaldosBackfillCommand extends Command
{
    /** @var string */
    protected $signature = 'saldos:backfill
                            {tenant? : UUID del tenant a procesar (omitir si se usa --all)}
                            {--fresh : Trunca cuenta_saldos antes de reconstruir}
                            {--sync : Ejecuta en este proceso (no encola)}
                            {--all : Procesa todos los tenants activos}';

    /** @var string */
    protected $description = 'Reconstruye la tabla materializada cuenta_saldos desde los asientos aprobados.';

    public function handle(BackfillSaldosService $service): int
    {
        $fresh = (bool) $this->option('fresh');
        $sync  = (bool) $this->option('sync');
        $all   = (bool) $this->option('all');

        $tenantArg = $this->argument('tenant');

        if ($all === false && $tenantArg === null) {
            $this->error('Debes indicar un tenant UUID o usar --all.');
            return self::INVALID;
        }

        if ($all && $tenantArg !== null) {
            $this->error('No combines un tenant específico con --all.');
            return self::INVALID;
        }

        if ($fresh && $sync === false) {
            // --fresh es destructivo: exigir confirmación cuando no es --sync (cola = no interactivo)
            $this->warn('--fresh trunca cuenta_saldos del tenant — operación destructiva.');
            if (! $this->confirm('¿Continuar?', default: false)) {
                $this->info('Cancelado.');
                return self::SUCCESS;
            }
        }

        $tenantIds = $all
            ? $this->tenantIdsActivos()
            : [$this->normalizarTenantId((string) $tenantArg)];

        if ($tenantIds === []) {
            $this->warn('No hay tenants para procesar.');
            return self::SUCCESS;
        }

        $errores = 0;
        foreach ($tenantIds as $tenantId) {
            try {
                if ($sync) {
                    $this->info("→ Backfill SYNC para tenant {$tenantId}" . ($fresh ? ' (--fresh)' : ''));
                    $resultado = $service->ejecutar($tenantId, $fresh);
                    $this->info(sprintf(
                        '  ✓ procesados=%d  omitidos=%d  completed=%s',
                        $resultado['processed'],
                        $resultado['skipped'],
                        $resultado['completed'] ? 'sí' : 'no',
                    ));
                } else {
                    BackfillSaldosJob::dispatch($tenantId, $fresh);
                    $this->info("→ Encolado backfill para tenant {$tenantId}" . ($fresh ? ' (--fresh)' : ''));
                }
            } catch (Throwable $e) {
                $errores++;
                $this->error("✗ Falló backfill para tenant {$tenantId}: {$e->getMessage()}");
            }
        }

        return $errores === 0 ? self::SUCCESS : self::FAILURE;
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

    private function normalizarTenantId(string $valor): string
    {
        $valor = trim($valor);
        // Tolerar comillas / espacios accidentales del shell
        return trim($valor, "\"' ");
    }
}
