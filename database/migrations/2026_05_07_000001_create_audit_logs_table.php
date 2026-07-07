<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de auditoría INMUTABLE en BD central.
 * Append-only enforced en aplicación (modelo) y trigger PostgreSQL.
 * Cumple Resolución DIAN 000042/2020 y art. 28 Código de Comercio.
 */
return new class extends Migration {
    public function up(): void
    {
        // En este proyecto stancl/tenancy usa la conexión por defecto para
        // las tablas centrales y crea conexiones dinámicas para tenants.
        // Esta migration vive en database/migrations/ (raíz) por lo que
        // corre contra la BD central.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('user_email_snapshot', 255)->nullable();
            $table->string('user_role_snapshot', 50)->nullable();
            $table->string('action', 80);
            $table->string('criticidad', 10); // info | warning | critical
            $table->string('auditable_type', 255)->nullable();
            $table->string('auditable_id', 36)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('motivo')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45);
            $table->string('user_agent', 500);
            $table->uuid('request_id')->nullable()->index();
            $table->uuid('sucursal_id')->nullable();
            $table->char('hash_anterior', 64)->nullable();
            $table->char('hash_actual', 64);
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['tenant_id', 'action', 'created_at'], 'idx_audit_tenant_action_date');
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_auditable');
            $table->index(['tenant_id', 'user_id', 'created_at'], 'idx_audit_user');
            $table->index(['tenant_id', 'criticidad', 'created_at'], 'idx_audit_crit');
        });

        // Trigger PostgreSQL anti UPDATE/DELETE (segunda barrera).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE OR REPLACE FUNCTION audit_logs_no_update_delete()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    RAISE EXCEPTION 'audit_logs es append-only: % no permitido', TG_OP;
                END;
                \$\$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER audit_logs_protect
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_no_update_delete();
            ');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_protect ON audit_logs;');
            DB::statement('DROP FUNCTION IF EXISTS audit_logs_no_update_delete();');
        }
        Schema::dropIfExists('audit_logs');
    }
};
