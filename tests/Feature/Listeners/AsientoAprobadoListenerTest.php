<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\Asiento\AsientoAprobado;
use App\Listeners\Saldos\ActualizarSaldosListener;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaSaldo;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * Tests de ActualizarSaldosListener — el listener síncrono que acumula deltas
 * en `cuenta_saldos` cuando se aprueba un asiento.
 *
 * En producción este listener corre DENTRO de la transacción de AsientoService::aprobar().
 * Aquí lo probamos directamente (sin HTTP) inyectándolo desde el container.
 */
class AsientoAprobadoListenerTest extends TenantTestCase
{
    private ActualizarSaldosListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->app->make(ActualizarSaldosListener::class);
    }

    public function test_listener_crea_fila_en_cuenta_saldos(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 7]);
        $cta1    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta1->id, 'debito' => '300000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '300000.0000'],
        ]);

        $this->listener->handle(new AsientoAprobado($asiento, $this->adminUser));

        $saldo1 = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cta1->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $saldo2 = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cta2->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $this->assertNotNull($saldo1, 'Debe existir saldo para cuenta débito');
        $this->assertEquals('300000.0000', $saldo1->movimiento_debito);
        $this->assertEquals('0.0000',      $saldo1->movimiento_credito);

        $this->assertNotNull($saldo2, 'Debe existir saldo para cuenta crédito');
        $this->assertEquals('0.0000',      $saldo2->movimiento_debito);
        $this->assertEquals('300000.0000', $saldo2->movimiento_credito);
    }

    public function test_listener_agrupa_multiples_lineas_de_la_misma_cuenta(): void
    {
        // SaldoUpserter::agruparLineas() colapsa líneas de la misma cuenta en un solo delta
        // antes del UPSERT → garantiza mínimo de filas en cuenta_saldos.
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 8]);
        $cta     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta->id,  'debito' => '150000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta->id,  'debito' => '50000.0000',  'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '200000.0000'],
        ]);

        $this->listener->handle(new AsientoAprobado($asiento, $this->adminUser));

        // Debe existir exactamente 1 fila en cuenta_saldos para esta cuenta+periodo
        $count = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cta->id)
            ->where('periodo_id', $periodo->id)
            ->count();

        $this->assertEquals(1, $count, 'SaldoUpserter debe colapsar las dos líneas en una sola fila');

        // El movimiento acumulado = 150000 + 50000 = 200000
        $saldo = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cta->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $this->assertEquals('200000.0000', $saldo->movimiento_debito);
    }

    public function test_listener_acumula_debitos_en_upsert_sucesivo(): void
    {
        // Dos asientos distintos sobre la misma cuenta: el segundo UPSERT debe ACUMULAR.
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 9]);
        $cta     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento1 = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta->id,  'debito' => '100000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '100000.0000'],
        ]);

        $asiento2 = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta->id,  'debito' => '80000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',     'credito' => '80000.0000'],
        ]);

        $this->listener->handle(new AsientoAprobado($asiento1, $this->adminUser));
        $this->listener->handle(new AsientoAprobado($asiento2, $this->adminUser));

        $saldo = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cta->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $this->assertNotNull($saldo);
        // 100000 + 80000 = 180000
        $this->assertEquals('180000.0000', $saldo->movimiento_debito);
        // saldo_final: 180000 (débito) - 0 (crédito) = 180000
        $this->assertEquals('180000.0000', $saldo->saldo_final_debito);
        $this->assertEquals('0.0000',      $saldo->saldo_final_credito);
    }

    public function test_listener_lanza_excepcion_si_asiento_sin_periodo_id(): void
    {
        // El listener valida que el asiento tenga periodo_id ANTES de cualquier
        // operación de BD — esta invariante protege contra corrupción de saldos.
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 10]);
        $cta1    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta1->id, 'debito' => '50000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',     'credito' => '50000.0000'],
        ]);

        // Simular asiento sin periodo_id en memoria (sin guardar en BD — no violaría la FK)
        $asiento->periodo_id = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sin periodo_id/');

        $this->listener->handle(new AsientoAprobado($asiento, $this->adminUser));
    }
}
