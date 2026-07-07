<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 40)->default('readonly_admin')->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 80)->unique();
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 14, 2)->default(0);
            $table->decimal('annual_price', 14, 2)->default(0);
            $table->char('currency', 3)->default('COP');
            $table->string('status', 30)->default('active')->index();
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_free')->default(false);
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->boolean('trial_allowed')->default(true);
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 100);
            $table->string('feature_label')->nullable();
            $table->integer('limit_value')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status', 40)->default('trialing')->index();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->char('currency', 3)->default('COP');
            $table->string('payment_status', 40)->default('not_configured')->index();
            $table->string('payment_method')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignUuid('previous_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUuid('new_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUuid('changed_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('reason', 255);
            $table->text('observation')->nullable();
            $table->string('effective_mode', 30)->default('immediate');
            $table->timestamp('effective_at')->nullable();
            $table->json('overuse_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('tenant_usage_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unsignedInteger('users_count')->default(0);
            $table->unsignedInteger('cost_centers_count')->default(0);
            $table->unsignedInteger('warehouses_count')->default(0);
            $table->unsignedInteger('requisitions_month_count')->default(0);
            $table->unsignedInteger('quotes_month_count')->default(0);
            $table->unsignedInteger('purchase_orders_month_count')->default(0);
            $table->unsignedInteger('invoices_month_count')->default(0);
            $table->unsignedInteger('products_count')->default(0);
            $table->unsignedInteger('third_parties_count')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedInteger('api_requests_month_count')->default(0);
            $table->timestamp('snapshot_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'snapshot_at']);
        });

        Schema::create('tenant_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreignUuid('changed_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('reason', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('platform_admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('action', 120)->index();
            $table->string('target_type', 120)->nullable();
            $table->string('target_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'status')) {
                $table->string('status', 40)->default('activa')->after('activo')->index();
            }
            if (! Schema::hasColumn('tenants', 'billing_status')) {
                $table->string('billing_status', 40)->default('not_configured')->after('status')->index();
            }
            if (! Schema::hasColumn('tenants', 'electronic_invoicing_status')) {
                $table->string('electronic_invoicing_status', 40)->default('not_configured')->after('billing_status');
            }
            if (! Schema::hasColumn('tenants', 'country')) {
                $table->string('country', 80)->nullable()->after('ciudad');
            }
            if (! Schema::hasColumn('tenants', 'last_access_at')) {
                $table->timestamp('last_access_at')->nullable()->after('trial_ends_at');
            }
            if (! Schema::hasColumn('tenants', 'storage_bytes_used')) {
                $table->unsignedBigInteger('storage_bytes_used')->default(0)->after('last_access_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            foreach ([
                'status',
                'billing_status',
                'electronic_invoicing_status',
                'country',
                'last_access_at',
                'storage_bytes_used',
            ] as $column) {
                if (Schema::hasColumn('tenants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('platform_admin_audit_logs');
        Schema::dropIfExists('tenant_status_history');
        Schema::dropIfExists('tenant_usage_snapshots');
        Schema::dropIfExists('subscription_history');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('platform_admins');
    }
};
