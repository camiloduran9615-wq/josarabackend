<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Verifica la integridad del hash chain de audit_logs.
 *
 * Uso:
 *   php artisan audit:verify-chain
 *   php artisan audit:verify-chain --tenant={uuid}
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain
        {--tenant= : UUID del tenant a verificar (opcional)}
        {--fail-fast : Detenerse al primer hash inválido}';

    protected $description = 'Verifica la integridad de la cadena hash de audit_logs';

    public function handle(AuditLogService $svc): int
    {
        $tenantsQuery = AuditLog::query()->select('tenant_id')->distinct();
        if ($tenantId = $this->option('tenant')) {
            $tenantsQuery->where('tenant_id', $tenantId);
        }
        $tenants = $tenantsQuery->pluck('tenant_id');

        if ($tenants->isEmpty()) {
            $this->info('No hay logs para verificar.');
            return self::SUCCESS;
        }

        $failures = 0;
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        foreach ($tenants as $tid) {
            $invalidId = $svc->verifyChainForTenant((string) $tid);
            if ($invalidId !== null) {
                $failures++;
                $this->newLine();
                $this->error("TAMPER DETECTED: tenant={$tid} log={$invalidId}");
                Log::critical('audit_chain_break', [
                    'tenant_id' => (string) $tid,
                    'log_id'    => $invalidId,
                ]);
                if ($this->option('fail-fast')) {
                    $bar->finish();
                    return self::FAILURE;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($failures > 0) {
            $this->error("Verificación falló para {$failures} tenant(s).");
            return self::FAILURE;
        }

        $this->info("Verificación OK para {$tenants->count()} tenant(s).");
        return self::SUCCESS;
    }
}
