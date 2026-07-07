<?php

declare(strict_types=1);

namespace Tests\Feature\Saldos;

use App\Services\Saldos\ReconciliarSaldosService;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * ReconciliarSaldosService — detecta drift entre cuenta_saldos y la suma real de asiento_items.
 */
final class ReconciliarSaldosServiceTest extends TenantTestCase
{
    private ReconciliarSaldosService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = app(ReconciliarSaldosService::class);
        $this->tenantId = $this->tenant->id;
    }

    protected function tearDown(): void
    {
        // ReconciliarSaldosService::reconciliar() calls Tenancy::end() internally.
        // Reinitialize so TenantTestCase::tearDown() can roll back the transaction.
        // stancl/tenancy v3 does not expose initialized() — check via tenant property.
        if (isset($this->tenant) && tenancy()->tenant === null) {
            tenancy()->initialize($this->tenant);
        }
        parent::tearDown();
    }

    public function test_reconciliacion_limpia_cuando_saldos_coinciden(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 11]);
        $ctaD    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $ctaC    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        // Asiento aprobado: DR 300.000 / CR 300.000
        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $ctaD->id, 'debito' => '300000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $ctaC->id, 'debito' => '0.0000',      'credito' => '300000.0000'],
        ], ['tipo_comprobante' => 'DB']);

        // Saldo materializado correcto (igual a lo real)
        $this->crearSaldo($ctaD, $periodo, ['movimiento_debito' => '300000.0000', 'saldo_final_debito' => '300000.0000']);
        $this->crearSaldo($ctaC, $periodo, ['movimiento_credito' => '300000.0000', 'saldo_final_credito' => '300000.0000']);

        $resultado = $this->service->reconciliar($this->tenantId, $periodo->id);

        $this->assertTrue($resultado->estaLimpio(), 'No debe haber drift cuando los datos coinciden.');
        $this->assertSame(0, $resultado->anomaliasCount);
    }

    public function test_reconciliacion_detecta_drift_en_movimiento_debito(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 12]);
        $ctaD    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $ctaC    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $ctaD->id, 'debito' => '500000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $ctaC->id, 'debito' => '0.0000',      'credito' => '500000.0000'],
        ], ['tipo_comprobante' => 'DB']);

        // Saldo materializado INCORRECTO — solo refleja 200.000 cuando debería ser 500.000
        $this->crearSaldo($ctaD, $periodo, ['movimiento_debito' => '200000.0000', 'saldo_final_debito' => '200000.0000']);
        $this->crearSaldo($ctaC, $periodo, ['movimiento_credito' => '500000.0000', 'saldo_final_credito' => '500000.0000']);

        $resultado = $this->service->reconciliar($this->tenantId, $periodo->id);

        $this->assertFalse($resultado->estaLimpio(), 'Debe detectar drift cuando los saldos no coinciden.');
        $this->assertGreaterThan(0, $resultado->anomaliasCount);
    }

    public function test_reconciliacion_sin_periodo_procesa_todo_el_tenant(): void
    {
        // Sin periodo_id → procesa todo el tenant
        $resultado = $this->service->reconciliar($this->tenantId, null);

        // Solo verificamos que el resultado tiene la estructura correcta
        $this->assertIsInt($resultado->filasComparadas);
        $this->assertIsInt($resultado->anomaliasCount);
        $this->assertIsString($resultado->deltaDebitoTotal);
        $this->assertIsString($resultado->deltaCreditoTotal);
        // duracionSegundos() uses DateTimeImmutable::getTimestamp() diff — returns int
        $this->assertIsInt($resultado->duracionSegundos());
    }

    public function test_resultado_limpio_tiene_delta_cero(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 10]);

        // Sin asientos ni saldos → reconciliación limpia con delta cero
        $resultado = $this->service->reconciliar($this->tenantId, $periodo->id);

        $this->assertTrue($resultado->estaLimpio());
        // When no anomalias are found, deltas stay at initial '0' (no Bc::add iterations)
        $this->assertEquals(0, (float) $resultado->deltaDebitoTotal);
        $this->assertEquals(0, (float) $resultado->deltaCreditoTotal);
    }
}
