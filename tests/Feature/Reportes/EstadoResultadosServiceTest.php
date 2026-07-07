<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Services\Reportes\DTOs\BloqueEstadoResultadosDto;
use App\Services\Reportes\EstadoResultadosService;
use Tests\TenantTestCase;

/**
 * EstadoResultadosService — estructura NIC 1 párr. 103 (por función).
 *
 * El servicio lee de cuenta_saldos (no de asiento_items directamente).
 * Los fixtures deben usar crearSaldo, no crearAsientoAprobado.
 */
final class EstadoResultadosServiceTest extends TenantTestCase
{
    private EstadoResultadosService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EstadoResultadosService::class);
    }

    public function test_er_retorna_estructura_con_bloques_esperados(): void
    {
        $resultado = $this->service->generate('2026-01-01', '2026-12-31', false);

        // EstadoResultadosDto tiene propiedades individuales, no un array $bloques
        $this->assertInstanceOf(BloqueEstadoResultadosDto::class, $resultado->ingresos);
        $this->assertInstanceOf(BloqueEstadoResultadosDto::class, $resultado->costoVentas);
        $this->assertInstanceOf(BloqueEstadoResultadosDto::class, $resultado->gastosOperacionales);
        $this->assertIsString($resultado->utilidadBruta);
        $this->assertIsString($resultado->utilidadOperacional);
        $this->assertIsString($resultado->utilidadNeta);
    }

    public function test_er_con_ingresos_y_gastos_calcula_utilidad(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 3]);

        // Ingreso operacional: clase 4
        $ctaIngreso = $this->crearCuenta([
            'naturaleza'        => 'credito',
            'clase'             => 4,
            'clasificacion_pyg' => 'operacional',
        ]);
        // Gasto operacional: clase 5
        $ctaGasto = $this->crearCuenta([
            'naturaleza'        => 'debito',
            'clase'             => 5,
            'clasificacion_pyg' => 'operacional',
        ]);

        // Service reads from cuenta_saldos — use crearSaldo, not crearAsientoAprobado
        $this->crearSaldo($ctaIngreso, $periodo, [
            'movimiento_credito'  => '2000000.0000',
            'saldo_final_credito' => '2000000.0000',
        ]);
        $this->crearSaldo($ctaGasto, $periodo, [
            'movimiento_debito'  => '800000.0000',
            'saldo_final_debito' => '800000.0000',
        ]);

        $resultado = $this->service->generate('2026-03-01', '2026-03-31', false);

        // Utilidad operacional debe ser positiva (ingresos 2M > gastos 800k)
        $this->assertGreaterThan(0, (float) $resultado->utilidadOperacional);
    }

    public function test_er_con_perdida_retorna_valor_negativo(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 4]);

        $ctaIngreso = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 4, 'clasificacion_pyg' => 'operacional']);
        $ctaGasto   = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 5, 'clasificacion_pyg' => 'operacional']);

        // Gasto > Ingreso → pérdida
        $this->crearSaldo($ctaIngreso, $periodo, [
            'movimiento_credito'  => '1000000.0000',
            'saldo_final_credito' => '1000000.0000',
        ]);
        $this->crearSaldo($ctaGasto, $periodo, [
            'movimiento_debito'  => '3000000.0000',
            'saldo_final_debito' => '3000000.0000',
        ]);

        $resultado = $this->service->generate('2026-04-01', '2026-04-30', false);

        // Pérdida: utilidadNeta negativa o cero
        $this->assertLessThanOrEqual(0, (float) $resultado->utilidadNeta);
    }

    public function test_er_comparativo_incluye_saldo_comparativo_en_bloques(): void
    {
        $resultado = $this->service->generate('2026-01-01', '2026-12-31', true);

        // Con comparativo=true, los totales comparativos están seteados (no null)
        $this->assertNotNull($resultado->utilidadNetaComparativa);
        $this->assertNotNull($resultado->ingresos->totalComparativo);
    }

    public function test_er_sin_datos_devuelve_bloques_con_total_cero(): void
    {
        // Periodo muy futuro donde no hay datos
        $resultado = $this->service->generate('2099-01-01', '2099-12-31', false);

        // Sin datos, ingresos->total es '0'
        $this->assertEquals(0, (float) $resultado->ingresos->total);
        $this->assertEquals(0, (float) $resultado->utilidadNeta);
    }
}
