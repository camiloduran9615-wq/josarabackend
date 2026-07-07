<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deprecación: la tabla audit_logs ya no vive por-tenant (épica EPIC-002).
 * El AuditLog se centraliza en la BD central para garantizar inmutabilidad
 * cross-tenant. Si este tenant tiene registros legacy, deben migrarse ANTES
 * con: php artisan migrate:audit-logs --tenant={uuid}
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            $count = DB::table('audit_logs')->count();
            if ($count > 0) {
                Log::warning(
                    "Tabla legacy audit_logs en tenant tiene {$count} registros que serán eliminados. "
                    .'Si necesitas conservarlos, ejecuta primero: '
                    .'php artisan migrate:audit-logs --tenant='.(tenant('id') ?? 'all')
                );
            }
            Schema::drop('audit_logs');
        }
    }

    public function down(): void
    {
        // No-op: no recreamos la tabla legacy. La fuente de verdad es la BD central.
    }
};
