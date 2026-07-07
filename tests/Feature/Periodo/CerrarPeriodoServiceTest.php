<?php

declare(strict_types=1);

namespace Tests\Feature\Periodo;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\PeriodoContable;
use App\Services\Periodo\CerrarPeriodoService;
use App\Services\Periodo\PeriodoOperacionInvalidaException;
use App\Services\Periodo\PreCierreFallidoException;
use Tests\TenantTestCase;

/**
 * Tests de CerrarPeriodoService (equivalente al "CierreMensualService" del plan).
 *
 * Cubre:
 *   - Cierre exitoso cambia estado → cerrado
 *   - Cierre con borradores pendientes → PreCierreFallidoException
 *   - Cierre de periodo ya cerrado → PeriodoOperacionInvalidaException
 *   - Checklist detecta borradores
 *   - Checklist valida balance cuadrado
 */
class CerrarPeriodoServiceTest extends TenantTestCase
{
    private CerrarPeriodoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CerrarPeriodoService::class);
    }

    public function test_cerrar_periodo_lo_marca_como_cerrado(): void
    {
        // Sin borradores ni asientos desbalanceados → cierre exitoso
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 2]);

        $cerrado = $this->service->ejecutar($periodo, $this->adminUser);

        $this->assertEquals(PeriodoContable::ESTADO_CERRADO, $cerrado->estado);
        $this->assertNotNull($cerrado->cerrado_at);
        $this->assertEquals($this->adminUser->id, $cerrado->cerrado_por_id);
    }

    public function test_cerrar_periodo_con_motivo_lo_registra(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 3]);

        $cerrado = $this->service->ejecutar($periodo, $this->adminUser, 'Cierre fin de mes marzo 2026');

        $this->assertEquals('Cierre fin de mes marzo 2026', $cerrado->motivo_cierre);
    }

    public function test_cerrar_con_borradores_lanza_excepcion_de_pre_cierre(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 4]);

        // Crear asiento en borrador dentro del periodo
        Asiento::withoutEvents(function () use ($periodo): void {
            $a = new Asiento();
            $a->forceFill([
                'tipo_comprobante' => 'DB',
                'comprobante'      => 'Diario Básico',
                'numero_documento' => 'BORRADOR-TEST-01',
                'fecha'            => $periodo->fecha_inicio,
                'periodo_id'       => $periodo->id,
                'año_fiscal'       => $periodo->año_fiscal,
                'glosa'            => 'Borrador pendiente de aprobación',
                'estado'           => Asiento::ESTADO_BORRADOR,
                'created_by_id'    => $this->adminUser->id,
            ]);
            $a->save();
        });

        $this->expectException(PreCierreFallidoException::class);
        $this->service->ejecutar($periodo, $this->adminUser);
    }

    public function test_cerrar_periodo_ya_cerrado_lanza_excepcion_de_operacion_invalida(): void
    {
        $periodo = $this->crearPeriodo([
            'año_fiscal' => 2026,
            'mes'        => 5,
            'estado'     => PeriodoContable::ESTADO_CERRADO,
        ]);

        $this->expectException(PeriodoOperacionInvalidaException::class);
        $this->service->ejecutar($periodo, $this->adminUser);
    }

    public function test_checklist_detecta_borradores_pendientes(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 6]);

        Asiento::withoutEvents(function () use ($periodo): void {
            $a = new Asiento();
            $a->forceFill([
                'tipo_comprobante' => 'DB',
                'comprobante'      => 'Diario',
                'numero_documento' => 'BORRADOR-TEST-02',
                'fecha'            => $periodo->fecha_inicio,
                'periodo_id'       => $periodo->id,
                'año_fiscal'       => $periodo->año_fiscal,
                'glosa'            => 'Sin aprobar',
                'estado'           => Asiento::ESTADO_BORRADOR,
                'created_by_id'    => $this->adminUser->id,
            ]);
            $a->save();
        });

        $checklist = $this->service->ejecutarChecklist($periodo);

        $itemBorradores = collect($checklist)->firstWhere('id', 'borradores_pendientes');
        $this->assertNotNull($itemBorradores);
        $this->assertFalse($itemBorradores['ok']);
    }

    public function test_checklist_balance_cuadra_sin_asientos(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 7]);

        $checklist = $this->service->ejecutarChecklist($periodo);

        $itemBalance = collect($checklist)->firstWhere('id', 'balance_cuadra');
        $this->assertNotNull($itemBalance);
        // Sin asientos: Σ débitos = 0, Σ créditos = 0 → diferencia = 0 → cuadra
        $this->assertTrue($itemBalance['ok']);
    }

    public function test_checklist_balance_cuadra_con_asientos_balanceados(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 8]);
        $cta1    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta1->id, 'debito' => '500000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '500000.0000'],
        ]);

        $checklist = $this->service->ejecutarChecklist($periodo);

        $itemBalance = collect($checklist)->firstWhere('id', 'balance_cuadra');
        $this->assertTrue($itemBalance['ok']);
    }
}
