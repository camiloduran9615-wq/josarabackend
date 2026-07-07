<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla efímera para flujos de aprobación dual (BD central).
 * Casos de uso: reapertura de periodo, anulación de factura DIAN aceptada.
 * TTL: 30 minutos. Job nocturno purga registros expirados.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('dual_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('action', 80);
            $table->string('subject_type', 255);
            $table->string('subject_id', 36);
            $table->uuid('requested_by_id');
            $table->json('payload')->nullable();
            $table->text('motivo');
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'action', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_approvals');
    }
};
