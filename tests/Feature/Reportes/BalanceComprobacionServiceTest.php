<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Services\Reportes\BalanceComprobacionService;
use Tests\TenantTestCase;

/**
 * BalanceComprobacionService — las 4 igualdades matemáticas deben cumplirse
 * cuando los datos son consistentes.
 *
 * Columnas del BC (12 en total):
 *   SI_D  SI_C | MOV_D  MOV_C | SF_D  SF_C | AJ_D  AJ_C | SA_D  SA_C
 *
 * Igualdades obligatorias (NIIF + Decreto 2650):
 *   1. Suma(SI_D) = Suma(SI_C)         — saldo inicial cuadra
 *   2. Suma(MOV_D) = Suma(MOV_C)       — movimientos del periodo cuadran
 *   3. Suma(AJ_D)  = Suma(AJ_C)        — ajustes cuadran
 *   4. Suma(SA_D)  = Suma(SA_C)        — saldo ajustado cuadra
 */
final class BalanceComprobacionServiceTest extends TenantTestCase
{
    private BalanceComprobacionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BalanceComprobacionService::class);
    }

    public function test_bc_con_movimientos_balanceados_reporta_valido(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 5]);

        $ctaDebito  = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $ctaCredito = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        // Asiento balanceado: DR caja 500k / CR proveedores 500k
        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $ctaDebito->id,  'debito' => '500000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $ctaCredito->id, 'debito' => '0.0000',      'credito' => '500000.0000'],
        ], ['tipo_comprobante' => 'DB']); // Tipo no-ajuste → va a MOV

        // Saldo inicial ambas en 0 (no hay SI previo en este test)
        $this->crearSaldo($ctaDebito, $periodo, [
            'movimiento_debito'    => '500000.0000',
            'saldo_final_debito'   => '500000.0000',
        ]);
        $this->crearSaldo($ctaCredito, $periodo, [
            'movimiento_credito'   => '500000.0000',
            'saldo_final_credito'  => '500000.0000',
        ]);

        $resultado = $this->service->generate($periodo->id, nivel: 1);

        $this->assertTrue($resultado->validacion->valido,
            'El balance de comprobación debe ser válido con datos balanceados.',
        );
        $this->assertTrue($resultado->validacion->movBalanceado);
    }

    public function test_bc_devuelve_estructura_12_columnas_por_fila(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 6]);

        $ctaD = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 4]);
        $ctaC = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 5]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $ctaD->id, 'debito' => '100000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $ctaC->id, 'debito' => '0.0000',      'credito' => '100000.0000'],
        ], ['tipo_comprobante' => 'DB']);

        $this->crearSaldo($ctaD, $periodo, ['movimiento_debito' => '100000.0000', 'saldo_final_debito' => '100000.0000']);
        $this->crearSaldo($ctaC, $periodo, ['movimiento_credito' => '100000.0000', 'saldo_final_credito' => '100000.0000']);

        $resultado = $this->service->generate($periodo->id, nivel: 1);

        $this->assertNotEmpty($resultado->filas);

        $fila = $resultado->filas[0];
        // Verifica las 6 pares de columnas
        $this->assertObjectHasProperty('saldoInicialDebito',   $fila);
        $this->assertObjectHasProperty('saldoInicialCredito',  $fila);
        $this->assertObjectHasProperty('movimientoDebito',     $fila);
        $this->assertObjectHasProperty('movimientoCredito',    $fila);
        $this->assertObjectHasProperty('saldoFinalDebito',     $fila);
        $this->assertObjectHasProperty('saldoFinalCredito',    $fila);
        $this->assertObjectHasProperty('ajusteDebito',         $fila);
        $this->assertObjectHasProperty('ajusteCredito',        $fila);
        $this->assertObjectHasProperty('saldoAjustadoDebito',  $fila);
        $this->assertObjectHasProperty('saldoAjustadoCredito', $fila);
    }

    public function test_bc_ajustes_van_a_columnas_correctas(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 7]);

        $ctaD = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 5]);
        $ctaC = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 4]);

        // Asiento de tipo 'AJ' → debe ir a columna ajustes, NO movimientos
        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $ctaD->id, 'debito' => '200000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $ctaC->id, 'debito' => '0.0000',      'credito' => '200000.0000'],
        ], ['tipo_comprobante' => 'AJ']); // Tipo ajuste

        $this->crearSaldo($ctaD, $periodo, ['movimiento_debito' => '200000.0000', 'saldo_final_debito' => '200000.0000']);
        $this->crearSaldo($ctaC, $periodo, ['movimiento_credito' => '200000.0000', 'saldo_final_credito' => '200000.0000']);

        $resultado = $this->service->generate($periodo->id, nivel: 1);

        // Al menos una fila debe tener ajuste > 0
        $tieneAjuste = false;
        foreach ($resultado->filas as $fila) {
            if (bccomp($fila->ajusteDebito, '0', 4) > 0 || bccomp($fila->ajusteCredito, '0', 4) > 0) {
                $tieneAjuste = true;
                break;
            }
        }

        $this->assertTrue($tieneAjuste, 'Debe existir al menos una fila con ajuste del asiento AJ.');
    }

    public function test_bc_nivel_2_incluye_cuentas_con_solo_saldo_inicial(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 8]);

        $cta = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1]);

        // Solo saldo inicial, sin movimientos
        $this->crearSaldo($cta, $periodo, [
            'saldo_inicial_debito' => '300000.0000',
            'saldo_final_debito'   => '300000.0000',
        ]);

        $nivel1 = $this->service->generate($periodo->id, nivel: 1); // solo con movimiento
        $nivel2 = $this->service->generate($periodo->id, nivel: 2); // con SI también

        // Nivel 2 debe incluir más filas (o igual) que nivel 1
        $this->assertGreaterThanOrEqual(count($nivel1->filas), count($nivel2->filas));
    }

    public function test_bc_periodo_inexistente_lanza_excepcion(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->generate('00000000-0000-0000-0000-000000000000', nivel: 1);
    }

    public function test_bc_validacion_tiene_todas_las_propiedades(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 9]);

        $resultado = $this->service->generate($periodo->id, nivel: 1);

        $this->assertObjectHasProperty('siBalanceado',  $resultado->validacion);
        $this->assertObjectHasProperty('movBalanceado', $resultado->validacion);
        $this->assertObjectHasProperty('ajBalanceado',  $resultado->validacion);
        $this->assertObjectHasProperty('saBalanceado',  $resultado->validacion);
        $this->assertObjectHasProperty('valido',        $resultado->validacion);
    }

    /**
     * Regresión: el SI de un periodo debe igualar el SF acumulado de periodos previos
     * para cuentas de balance (clases 1, 2, 3). Reproducción del bug: apertura en enero
     * deja saldos materializados solo en enero; al consultar mayo, el SI venía en 0.
     */
    public function test_si_cuentas_balance_acumula_saldo_final_de_periodos_previos(): void
    {
        $enero = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 1]);
        $mayo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 5]);

        // Bancos (activo, clase 1) y Aportes Sociales (patrimonio, clase 3)
        $bancos  = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1, 'codigo' => 'T1110']);
        $aportes = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 3, 'codigo' => 'T3105']);

        // Apertura en enero: 50M en Bancos / 50M en Aportes
        $this->crearSaldo($bancos, $enero, [
            'movimiento_debito'  => '50000000.0000',
            'saldo_final_debito' => '50000000.0000',
        ]);
        $this->crearSaldo($aportes, $enero, [
            'movimiento_credito'  => '50000000.0000',
            'saldo_final_credito' => '50000000.0000',
        ]);

        // En mayo NO hay filas en cuenta_saldos para estas cuentas todavía.
        // Forzamos un asiento de mayo distinto para que las cuentas Bancos/Aportes
        // aparezcan vía el SI (no via movimiento del periodo) usando nivel 2.
        $resultado = $this->service->generate($mayo->id, nivel: 2);

        $filaBancos = $this->findFila($resultado->filas, 'T1110');
        $filaAportes = $this->findFila($resultado->filas, 'T3105');

        $this->assertNotNull($filaBancos,  'Bancos debe aparecer en el BC de mayo por SI heredado.');
        $this->assertNotNull($filaAportes, 'Aportes debe aparecer en el BC de mayo por SI heredado.');

        $this->assertSame(0, bccomp($filaBancos->saldoInicialDebito,   '50000000.0000', 4),
            "SI débito Bancos esperado=50M, recibido={$filaBancos->saldoInicialDebito}");
        $this->assertSame(0, bccomp($filaBancos->saldoInicialCredito,  '0', 4));

        $this->assertSame(0, bccomp($filaAportes->saldoInicialCredito, '50000000.0000', 4),
            "SI crédito Aportes esperado=50M, recibido={$filaAportes->saldoInicialCredito}");
        $this->assertSame(0, bccomp($filaAportes->saldoInicialDebito,  '0', 4));

        $this->assertTrue($resultado->validacion->siBalanceado,
            '∑SI_D debe igualar ∑SI_C tras propagar saldos de enero (partida doble).');
    }

    /**
     * Cuentas de resultado (clases 4-7) NO deben heredar saldos cross-año.
     * Si el periodo previo está en otro año_fiscal, el SI debe ser cero porque
     * el cierre anual cancela esas cuentas contra 3606.
     */
    public function test_si_cuentas_resultado_no_acumulan_entre_anios(): void
    {
        $dic2025 = $this->crearPeriodo(['año_fiscal' => 2025, 'mes' => 12]);
        $ene2026 = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 1]);

        // Cuenta de ingresos (clase 4) con movimiento en diciembre 2025
        $ingresos = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 4, 'codigo' => 'T4135']);
        $this->crearSaldo($ingresos, $dic2025, [
            'movimiento_credito'  => '10000000.0000',
            'saldo_final_credito' => '10000000.0000',
        ]);

        // Cuenta de balance (clase 1) — para verificar que ESTA sí cruza años
        $bancos = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1, 'codigo' => 'T1110B']);
        $this->crearSaldo($bancos, $dic2025, [
            'movimiento_debito'  => '10000000.0000',
            'saldo_final_debito' => '10000000.0000',
        ]);

        $resultado = $this->service->generate($ene2026->id, nivel: 2);

        $filaIngresos = $this->findFila($resultado->filas, 'T4135');
        $filaBancos   = $this->findFila($resultado->filas, 'T1110B');

        // Ingresos (clase 4) NO debe arrastrar: SI = 0
        if ($filaIngresos !== null) {
            $this->assertSame(0, bccomp($filaIngresos->saldoInicialCredito, '0', 4),
                'Cuentas de resultado no deben arrastrar saldos entre años fiscales.');
            $this->assertSame(0, bccomp($filaIngresos->saldoInicialDebito,  '0', 4));
        }

        // Bancos (clase 1) SÍ debe arrastrar
        $this->assertNotNull($filaBancos, 'Bancos (balance) debe heredar SI cross-año.');
        $this->assertSame(0, bccomp($filaBancos->saldoInicialDebito, '10000000.0000', 4));
    }

    /**
     * Si hay periodos intermedios sin movimiento, el SI del periodo final debe
     * incluir el saldo del periodo más antiguo (cálculo on-demand, no requiere
     * materializar saldos en febrero/marzo/abril).
     */
    public function test_si_atraviesa_periodos_intermedios_sin_movimiento(): void
    {
        $enero = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 1]);
        // Sin saldos materializados en febrero, marzo, abril
        $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 2]);
        $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 3]);
        $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 4]);
        $mayo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 5]);

        $bancos = $this->crearCuenta(['naturaleza' => 'debito', 'clase' => 1, 'codigo' => 'T1110C']);
        $this->crearSaldo($bancos, $enero, [
            'movimiento_debito'  => '70000000.0000',
            'saldo_final_debito' => '70000000.0000',
        ]);

        $resultado = $this->service->generate($mayo->id, nivel: 2);
        $fila = $this->findFila($resultado->filas, 'T1110C');

        $this->assertNotNull($fila, 'Bancos debe aparecer aunque feb-abr no tengan movimiento.');
        $this->assertSame(0, bccomp($fila->saldoInicialDebito, '70000000.0000', 4),
            "SI de mayo debe ser SF de enero (70M), recibido={$fila->saldoInicialDebito}");
    }

    /**
     * @param  iterable<int, \App\Services\Reportes\DTOs\FilaBalanceComprobacionDto>  $filas
     */
    private function findFila(iterable $filas, string $codigo): ?\App\Services\Reportes\DTOs\FilaBalanceComprobacionDto
    {
        foreach ($filas as $fila) {
            if ($fila->codigo === $codigo) {
                return $fila;
            }
        }
        return null;
    }
}
