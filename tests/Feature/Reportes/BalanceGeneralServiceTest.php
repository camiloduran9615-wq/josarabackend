<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Services\Reportes\BalanceGeneralService;
use Tests\TenantTestCase;

/**
 * BalanceGeneralService — ecuación contable, comparativo, cache.
 *
 * Crea cuentas + saldos ficticios que cumplan la ecuación Activo = Pasivo + Patrimonio,
 * luego verifica que el servicio los agrupe correctamente y el balance cuadre.
 */
final class BalanceGeneralServiceTest extends TenantTestCase
{
    private BalanceGeneralService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BalanceGeneralService::class);
    }

    public function test_balance_general_con_saldos_balanceados(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 3]);

        // Activo corriente: +1.000.000
        $caja = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1, 'clasificacion_balance' => 'corriente']);
        $this->crearSaldo($caja, $periodo, [
            'movimiento_debito'  => '1000000.0000',
            'saldo_final_debito' => '1000000.0000',
        ]);

        // Pasivo corriente: +600.000
        $proveedores = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2, 'clasificacion_balance' => 'corriente']);
        $this->crearSaldo($proveedores, $periodo, [
            'movimiento_credito'  => '600000.0000',
            'saldo_final_credito' => '600000.0000',
        ]);

        // Patrimonio: +400.000
        $capital = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 3, 'clasificacion_balance' => 'na']);
        $this->crearSaldo($capital, $periodo, [
            'movimiento_credito'  => '400000.0000',
            'saldo_final_credito' => '400000.0000',
        ]);

        $resultado = $this->service->generate('2026-03-31', false);

        // Ecuación: Activo = Pasivo + Patrimonio
        $this->assertTrue($resultado->ecuacion->balanceado);
        $this->assertGreaterThan(0, (float) $resultado->activo->total);
    }

    public function test_balance_general_ecuacion_no_balanceada_detectada(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 4]);

        // Solo Activo sin contrapartida → no balance
        $caja = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1, 'clasificacion_balance' => 'corriente']);
        $this->crearSaldo($caja, $periodo, [
            'movimiento_debito'  => '999999.0000',
            'saldo_final_debito' => '999999.0000',
        ]);

        $resultado = $this->service->generate('2026-04-30', false);

        // La ecuación reporta la diferencia aunque no cuadre
        $this->assertNotNull($resultado->ecuacion->diferencia);
        $this->assertFalse($resultado->ecuacion->balanceado);
    }

    public function test_generate_retorna_estructura_esperada(): void
    {
        // Need saldo data so subsecciones get populated (service skips empty sets)
        $periodo       = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 6]);
        $ctaCorriente  = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1, 'clasificacion_balance' => 'corriente']);
        $ctaNoCorriente = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1, 'clasificacion_balance' => 'no_corriente']);
        $this->crearSaldo($ctaCorriente,   $periodo, ['movimiento_debito' => '100000.0000', 'saldo_final_debito' => '100000.0000']);
        $this->crearSaldo($ctaNoCorriente, $periodo, ['movimiento_debito' => '200000.0000', 'saldo_final_debito' => '200000.0000']);

        $resultado = $this->service->generate('2026-12-31', false);

        // Estructura mínima requerida — DTOs con propiedades tipadas
        $this->assertIsBool($resultado->ecuacion->balanceado);
        $this->assertIsString($resultado->activo->total);
        $this->assertIsString($resultado->pasivo->total);
        $this->assertIsString($resultado->patrimonio->total);

        // Activo tiene subsecciones Corriente / No Corriente
        $nombresSubs = array_map(fn ($s) => $s->nombre, $resultado->activo->subsecciones);
        $this->assertContains('Activos Corrientes', $nombresSubs);
        $this->assertContains('Activos No Corrientes', $nombresSubs);
    }

    public function test_generate_comparativo_incluye_campos_de_año_anterior(): void
    {
        // Positional arg — método firma: generate(string $fechaCorte, bool $comparativoAnioAnterior)
        $resultado = $this->service->generate('2026-12-31', true);

        // Con comparativo=true, fechaComparativo y totalAnterior deben estar presentes
        $this->assertNotNull($resultado->fechaComparativo);
        $this->assertNotNull($resultado->activo->totalAnterior);
        $this->assertNotNull($resultado->pasivo->totalAnterior);
        $this->assertNotNull($resultado->patrimonio->totalAnterior);
    }

    public function test_meta_incluye_tiempo_ms(): void
    {
        $resultado = $this->service->generate('2026-12-31', false);

        $this->assertIsInt($resultado->tiempoMs);
        $this->assertGreaterThanOrEqual(0, $resultado->tiempoMs);
    }
}
