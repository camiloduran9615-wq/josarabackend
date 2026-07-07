<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Migra registros legacy de audit_logs (BD por tenant) a la BD central.
 *
 * Uso:
 *   php artisan migrate:audit-logs                      # todos los tenants
 *   php artisan migrate:audit-logs --tenant={uuid}      # un solo tenant
 *   php artisan migrate:audit-logs --dry-run            # sin escribir
 */
class MigrateAuditLogsToCentral extends Command
{
    protected $signature = 'audit:migrate-legacy
        {--tenant= : UUID del tenant (opcional)}
        {--dry-run : No persistir cambios}';

    protected $description = 'Migra audit_logs legacy del tenant a la BD central (uso único pre-deploy EPIC-002)';

    public function handle(AuditLogService $svc): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::query()->whereKey($this->option('tenant'))->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->info('No hay tenants para procesar.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalMigrados = 0;

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($tenant, $svc, $dryRun, &$totalMigrados): void {
                if (! Schema::hasTable('audit_logs')) {
                    $this->line("Tenant {$tenant->id}: sin tabla legacy, saltando.");
                    return;
                }

                $rows = DB::table('audit_logs')->orderBy('created_at')->get();
                $this->line("Tenant {$tenant->id}: {$rows->count()} registros.");

                if ($dryRun) {
                    return;
                }

                $prevHash = null;
                foreach ($rows as $r) {
                    $payload = [
                        'id'                  => (string) Str::uuid(),
                        'tenant_id'           => (string) $tenant->id,
                        'user_id'             => $r->user_id,
                        'user_email_snapshot' => $r->user_email,
                        'user_role_snapshot'  => null,
                        'action'              => $r->action,
                        'criticidad'          => 'info',
                        'auditable_type'      => $r->entity_type,
                        'auditable_id'        => $r->entity_id,
                        'old_values'          => $r->old_values
                            ? (is_string($r->old_values) ? json_decode($r->old_values, true) : $r->old_values)
                            : null,
                        'new_values'          => $r->new_values
                            ? (is_string($r->new_values) ? json_decode($r->new_values, true) : $r->new_values)
                            : null,
                        'motivo'              => null,
                        'metadata'            => ['migrated_from_legacy' => true, 'module' => $r->module],
                        'ip_address'          => $r->ip_address ?? '127.0.0.1',
                        'user_agent'          => $r->user_agent ?? 'system:migration',
                        'request_id'          => null,
                        'sucursal_id'         => null,
                        'hash_anterior'       => $prevHash,
                        'created_at'          => $r->created_at,
                    ];

                    $payload['hash_actual'] = $svc->computeHash($payload, $prevHash);

                    AuditLog::query()->create($payload);
                    $prevHash = $payload['hash_actual'];
                    $totalMigrados++;
                }
            });
        }

        $verbo = $dryRun ? 'serían migrados' : 'migrados';
        $this->info("{$totalMigrados} registros {$verbo}.");

        return self::SUCCESS;
    }
}
