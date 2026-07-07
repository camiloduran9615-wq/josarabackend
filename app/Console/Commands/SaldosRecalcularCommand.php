<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Saldos\RecalcularPeriodoJob;
use App\Models\Tenant as TenantCentralModel;
use App\Services\Saldos\RecalcularPeriodoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Facades\Tenancy;
use Throwable;

/**
 * Comando de recovery manual para saldos de un periodo específico.
 *
 * Uso típico:
 *   php artisan saldos:recalcular <tenant_uuid> <periodo_uuid>         # encola RecalcularPeriodoJob
 *   php artisan saldos:recalcular <tenant_uuid> <periodo_uuid> --sync  # ejecuta en este proceso
 *   php artisan saldos:recalcular <tenant_uuid>                        # lista periodos del tenant
 *
 * Cuando el periodo_id no se proporciona, el comando lista los periodos contables
 * del tenant (con sus IDs) para que el operador pueda elegir el correcto.
 *
 * Diferencia con saldos:backfill:
 *  - backfill procesa TODOS los asientos del tenant con checkpoint reanudable.
 *  - recalcular opera en una transacción única sobre UN solo periodo (quirúrgico).
 */
final class SaldosRecalcularCommand extends Command
{
    /** @var string */
    protected $signature = 'saldos:recalcular
                            {tenant : UUID del tenant}
                            {periodo? : UUID del periodo contable a recalcular}
                            {--sync : Ejecuta en este proceso (no encola)}';

    /** @var string */
    protected $description = 'Recalcula cuenta_saldos para un periodo específico (recovery quirúrgico).';

    public function handle(RecalcularPeriodoService $service): int
    {
        $tenantId  = trim((string) $this->argument('tenant'), "\"' ");
        $periodoId = $this->argument('periodo');
        $sync      = (bool) $this->option('sync');

        $tenant = TenantCentralModel::query()->find($tenantId);
        if ($tenant === null) {
            $this->error("Tenant {$tenantId} no existe.");
            return self::FAILURE;
        }

        // Sin periodo: listar periodos disponibles y salir
        if ($periodoId === null) {
            $this->listPeriodos($tenant);
            return self::SUCCESS;
        }

        $periodoId = trim((string) $periodoId, "\"' ");

        if (! $this->verificarPeriodoExiste($tenant, $periodoId)) {
            $this->error("Periodo {$periodoId} no existe en el tenant {$tenantId}.");
            return self::FAILURE;
        }

        $this->warn("Esto reseteará movimientos del periodo {$periodoId} y los reconstruirá desde asientos aprobados.");
        if (! $sync && ! $this->confirm('¿Continuar? (se encolará el job)', default: true)) {
            $this->info('Cancelado.');
            return self::SUCCESS;
        }

        try {
            if ($sync) {
                $this->info("→ Recalculando SYNC tenant={$tenantId} periodo={$periodoId}...");
                $r = $service->recalcular($tenantId, $periodoId);
                $this->info(sprintf(
                    '✓ Completado. Asientos procesados: %d | Líneas: %d',
                    $r['asientos_procesados'],
                    $r['lineas_procesadas'],
                ));
            } else {
                RecalcularPeriodoJob::dispatch($tenantId, $periodoId);
                $this->info("→ Encolado RecalcularPeriodoJob para tenant={$tenantId} periodo={$periodoId}");
            }
        } catch (Throwable $e) {
            $this->error("✗ Falló: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function listPeriodos(TenantCentralModel $tenant): void
    {
        $this->info("Periodos disponibles para tenant {$tenant->id}:");

        try {
            Tenancy::initialize($tenant);

            /** @var list<object{id: string, codigo: string, tipo: string, año_fiscal: int, mes: int|null, estado: string, fecha_inicio: string}> $rows */
            $rows = DB::select(<<<'SQL'
                SELECT id, codigo, tipo, año_fiscal, mes, estado, fecha_inicio
                FROM periodos_contables
                ORDER BY fecha_inicio DESC
                LIMIT 50
            SQL);

            Tenancy::end();
        } catch (Throwable $e) {
            try {
                Tenancy::end();
            } catch (Throwable) {}
            $this->error("No se pudo listar periodos: {$e->getMessage()}");
            return;
        }

        if (empty($rows)) {
            $this->warn('  Sin periodos contables registrados.');
            return;
        }

        $this->table(
            ['ID', 'Código', 'Tipo', 'Año', 'Mes', 'Estado', 'Inicio'],
            array_map(static fn (object $r): array => [
                $r->id,
                $r->codigo,
                $r->tipo,
                (string) $r->año_fiscal,
                $r->mes !== null ? (string) $r->mes : '—',
                $r->estado,
                $r->fecha_inicio,
            ], $rows),
        );

        $this->line('');
        $this->line('Ejemplo de uso:');
        $this->line("  php artisan saldos:recalcular {$tenant->id} <periodo_id> --sync");
    }

    private function verificarPeriodoExiste(TenantCentralModel $tenant, string $periodoId): bool
    {
        try {
            Tenancy::initialize($tenant);
            $existe = DB::selectOne('SELECT id FROM periodos_contables WHERE id = ?', [$periodoId]) !== null;
            Tenancy::end();
            return $existe;
        } catch (Throwable) {
            try {
                Tenancy::end();
            } catch (Throwable) {}
            return false;
        }
    }
}
