<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\Saldos\BackfillSaldosJob;
use App\Services\Saldos\BackfillSaldosService;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * Tests de BackfillSaldosJob y BackfillSaldosService.
 *
 * El servicio llama internamente a Tenancy::initialize() y Tenancy::end() múltiples
 * veces (una por chunk, para actualizar el checkpoint en BD central). Esto hace
 * imposible testear el flujo completo dentro del rollback transaccional de TenantTestCase.
 *
 * Estrategia:
 *   - Estructura del Job: tests puros sin BD.
 *   - Error paths del servicio que ocurren ANTES de la primera llamada a
 *     Tenancy::initialize() (ej: tenant inexistente): sí se pueden testear.
 *   - Flujo completo (idempotencia, checkpoint): marcado como incompleto hasta
 *     implementar suite de integración sin transacción de rollback.
 */
class BackfillSaldosJobTest extends TenantTestCase
{
    // ── Estructura del Job ─────────────────────────────────────────────────────

    public function test_job_tiene_tres_intentos(): void
    {
        $job = new BackfillSaldosJob('00000000-0000-0000-0000-000000000001');

        $this->assertEquals(3, $job->tries);
    }

    public function test_job_tiene_timeout_de_una_hora(): void
    {
        $job = new BackfillSaldosJob('00000000-0000-0000-0000-000000000001');

        $this->assertEquals(3600, $job->timeout);
    }

    public function test_job_unique_id_incluye_tenant_id(): void
    {
        $tenantId = '99900000-0000-0000-0000-000000000001';
        $job      = new BackfillSaldosJob($tenantId);

        $this->assertStringContainsString($tenantId, $job->uniqueId());
    }

    public function test_job_backoff_tiene_tres_escalones_exponenciales(): void
    {
        $job = new BackfillSaldosJob('00000000-0000-0000-0000-000000000001');

        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_job_flag_fresh_se_preserva(): void
    {
        $jobNormal = new BackfillSaldosJob('t1', fresh: false);
        $jobFresh  = new BackfillSaldosJob('t1', fresh: true);

        $this->assertFalse($jobNormal->fresh);
        $this->assertTrue($jobFresh->fresh);
    }

    // ── Error paths del servicio ──────────────────────────────────────────────

    public function test_servicio_lanza_excepcion_con_tenant_inexistente(): void
    {
        // BackfillSaldosService consulta la BD central para verificar el tenant.
        // Si no existe, lanza RuntimeException ANTES de llamar a Tenancy::initialize(),
        // por lo que este test es compatible con el rollback de TenantTestCase.
        $service = $this->app->make(BackfillSaldosService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no existe/');

        $service->ejecutar('00000000-0000-0000-0000-ffffffffffff');
    }

    // ── Tests de integración completa ─────────────────────────────────────────

    public function test_backfill_completo_es_idempotente(): void
    {
        $this->markTestIncomplete(
            'Pendiente: el backfill completo requiere múltiples llamadas a Tenancy::initialize()/end() ' .
            'para actualizar checkpoints en la BD central, lo que destruye la transacción de rollback ' .
            'de TenantTestCase. Requiere suite de integración con base de datos limpiada manualmente.',
        );
    }

    public function test_backfill_fresh_trunca_saldos_previos(): void
    {
        $this->markTestIncomplete(
            'Pendiente: misma restricción que test_backfill_completo_es_idempotente.',
        );
    }
}
