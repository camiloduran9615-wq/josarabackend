<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_operation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category', 60)->index();
            $table->string('severity', 30)->index();
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->string('source', 120)->nullable()->index();
            $table->string('target_type', 120)->nullable();
            $table->string('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->foreignUuid('acknowledged_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignUuid('resolved_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'severity', 'created_at']);
        });

        Schema::create('platform_status_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('service_key', 100)->index();
            $table->string('service_name', 180);
            $table->string('status', 30)->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('region', 80)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();

            $table->index(['service_key', 'checked_at']);
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 120)->unique();
            $table->string('group', 80)->default('general')->index();
            $table->string('type', 40)->default('string');
            $table->json('value')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->foreignUuid('updated_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->string('subject', 180);
            $table->string('status', 40)->default('open')->index();
            $table->string('priority', 30)->default('normal')->index();
            $table->string('requester_email')->nullable();
            $table->foreignUuid('assigned_to_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->foreignUuid('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_status_checks');
        Schema::dropIfExists('platform_operation_events');
    }
};
