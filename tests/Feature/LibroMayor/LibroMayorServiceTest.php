<?php

declare(strict_types=1);

namespace Tests\Feature\LibroMayor;

use App\Services\LibroMayor\DTOs\LibroMayorResultDto;
use App\Services\LibroMayor\DTOs\MovimientoLibroMayorDto;
use App\Services\LibroMayor\LibroMayorService;
use Tests\TenantTestCase;

class LibroMayorServiceTest extends TenantTestCase
{
    private LibroMayorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LibroMayorService::class);
    }

    public function test_lm_cuenta_inexistente_lanza_excepcion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->query('00000000-0000-0000-0000-000000000000');
    }

    public function test_lm_retorna_estructura_correcta(): void
    {
        $cuenta = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1]);

        $resultado = $this->service->query($cuenta->id);

        $this->assertInstanceOf(LibroMayorResultDto::class, $resultado);

        // Estructura cuenta
        $this->assertArrayHasKey('id',         $resultado->cuenta);
        $this->assertArrayHasKey('codigo',     $resultado->cuenta);
        $this->assertArrayHasKey('nombre',     $resultado->cuenta);
        $this->assertArrayHasKey('naturaleza', $resultado->cuenta);
        $this->assertEquals((string) $cuenta->id, $resultado->cuenta['id']);

        // Estructura saldos
        $this->assertArrayHasKey('saldo_inicial_debito',  $resultado->saldos);
        $this->assertArrayHasKey('saldo_inicial_credito', $resultado->saldos);
        $this->assertArrayHasKey('movimiento_debito',     $resultado->saldos);
        $this->assertArrayHasKey('movimiento_credito',    $resultado->saldos);
        $this->assertArrayHasKey('saldo_final_debito',    $resultado->saldos);
        $this->assertArrayHasKey('saldo_final_credito',   $resultado->saldos);

        // Estructura paginación
        $this->assertArrayHasKey('total',     $resultado->paginacion);
        $this->assertArrayHasKey('page',      $resultado->paginacion);
        $this->assertArrayHasKey('per_page',  $resultado->paginacion);
        $this->assertArrayHasKey('last_page', $resultado->paginacion);
    }

    public function test_lm_saldos_agrupados_desde_cuenta_saldos(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 3]);
        $cuenta  = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1]);

        $this->crearSaldo($cuenta, $periodo, [
            'movimiento_debito'  => '500000.0000',
            'movimiento_credito' => '200000.0000',
            'saldo_final_debito' => '300000.0000',
        ]);

        $resultado = $this->service->query($cuenta->id, ['periodo_id' => $periodo->id]);

        $this->assertEquals('500000.0000', $resultado->saldos['movimiento_debito']);
        $this->assertEquals('200000.0000', $resultado->saldos['movimiento_credito']);
        $this->assertEquals('300000.0000', $resultado->saldos['saldo_final_debito']);
    }

    public function test_lm_movimientos_incluyen_saldo_acumulado(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 4]);
        $cta     = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta->id,  'debito' => '100000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '100000.0000'],
        ]);

        $resultado = $this->service->query($cta->id, [
            'periodo_id'          => $periodo->id,
            'incluir_movimientos' => true,
        ]);

        $this->assertNotEmpty($resultado->movimientos);

        foreach ($resultado->movimientos as $mov) {
            $this->assertInstanceOf(MovimientoLibroMayorDto::class, $mov);
            $this->assertNotNull($mov->saldoAcumulado);
            $this->assertIsString($mov->saldoAcumulado);
        }
    }

    public function test_lm_sin_movimientos_retorna_lista_vacia(): void
    {
        $cuenta = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1]);

        $resultado = $this->service->query($cuenta->id, ['incluir_movimientos' => false]);

        $this->assertIsArray($resultado->movimientos);
        $this->assertEmpty($resultado->movimientos);
        $this->assertEquals(0, $resultado->paginacion['total']);
        $this->assertEquals(1, $resultado->paginacion['last_page']);
    }

    public function test_lm_filtro_por_periodo_excluye_otros_periodos(): void
    {
        $periodoA = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 5]);
        $periodoB = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 6]);
        $cuenta   = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1]);

        $this->crearSaldo($cuenta, $periodoA, [
            'movimiento_debito'  => '111111.0000',
            'saldo_final_debito' => '111111.0000',
        ]);
        $this->crearSaldo($cuenta, $periodoB, [
            'movimiento_debito'  => '222222.0000',
            'saldo_final_debito' => '222222.0000',
        ]);

        // Consulta solo del periodo A
        $resultado = $this->service->query($cuenta->id, ['periodo_id' => $periodoA->id]);

        $this->assertEquals('111111.0000', $resultado->saldos['movimiento_debito']);
    }
}
