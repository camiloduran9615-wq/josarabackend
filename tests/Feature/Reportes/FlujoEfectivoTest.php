<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-G: Estado de Flujo de Efectivo (NIC 7) método indirecto.
 *
 * Estructura del reporte:
 *  1. Operación: utilidad neta + depreciación + cambios capital trabajo
 *  2. Inversión: variaciones en clases 15 (PPE), 16 (intangibles), 18 (inversiones)
 *  3. Financiación: variaciones en clase 21 (préstamos), 31 (capital), 37 (dividendos)
 *  4. Aumento de efectivo + saldo inicial = saldo final calculado
 *  5. Conciliación con saldo real de cuenta 11 al cierre
 */
class FlujoEfectivoTest extends TenantTestCase
{
    private User $contador;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupBase(int $anio): array
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'EFE',
            'email'    => 'efe-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Cuentas para los movimientos
        $cuentas = [
            'banco'         => $this->getOrCreateCuenta('111105', 'Bancos Test',         'debito',  1),
            'cliente'       => $this->getOrCreateCuenta('130505', 'Clientes Test',       'debito',  1),
            'proveedor'     => $this->getOrCreateCuenta('220505', 'Proveedores Test',    'credito', 2),
            'ingreso'       => $this->getOrCreateCuenta('413505', 'Ventas Test',         'credito', 4),
            'gasto'         => $this->getOrCreateCuenta('510506', 'Sueldos Test',        'debito',  5),
            'capital'       => $this->getOrCreateCuenta('310505', 'Capital Test',        'credito', 3),
            'activo_fijo'   => $this->getOrCreateCuenta('152410', 'Activo Fijo Test',    'debito',  1),
        ];

        // Periodos del año
        $periodos = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $codigo = sprintf('%04d-%02d', $anio, $mes);
            if (PeriodoContable::where('codigo', $codigo)->doesntExist()) {
                $periodos[$mes] = $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => $mes]);
            } else {
                $periodos[$mes] = PeriodoContable::where('codigo', $codigo)->first();
            }
        }

        return compact('cuentas', 'periodos');
    }

    private function getOrCreateCuenta(string $codigo, string $nombre, string $naturaleza, int $clase): CuentaContable
    {
        return CuentaContable::updateOrCreate(
            ['codigo' => $codigo],
            [
                'nombre'                => $nombre,
                'naturaleza'            => $naturaleza,
                'nivel'                 => 'subcuenta',
                'clase'                 => $clase,
                'acepta_movimientos'    => true,
                'exige_tercero'         => false,
                'exige_centro_costo'    => false,
                'exige_base_impuesto'   => false,
                'clasificacion_balance' => 'corriente',
                'clasificacion_pyg'     => 'na',
                'sistema'               => true,
                'editable'              => false,
                'activo'                => true,
            ],
        );
    }

    public function test_feat_g_estructura_basica_con_utilidad_neta(): void
    {
        $anio = 2040;
        $base = $this->setupBase($anio);

        // Generar utilidad: ingreso 10M, gasto 4M → utilidad 6M
        $this->crearAsientoAprobado(
            $base['periodos'][6],
            [
                ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '10000000', 'credito' => '0'],
                ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0', 'credito' => '10000000'],
            ],
            ['fecha' => "{$anio}-06-15"],
        );
        $this->crearAsientoAprobado(
            $base['periodos'][7],
            [
                ['cuenta_id' => $base['cuentas']['gasto']->id, 'debito' => '4000000', 'credito' => '0'],
                ['cuenta_id' => $base['cuentas']['banco']->id, 'debito' => '0', 'credito' => '4000000'],
            ],
            ['fecha' => "{$anio}-07-10"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/flujo-efectivo?año=' . $anio));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.anio', $anio);

        // Utilidad neta = 10M - 4M = 6M
        $this->assertEqualsWithDelta(6_000_000.0,
            (float) $resp->json('data.totales_pyg.utilidad_neta'),
            0.01);

        // Operación debe incluir la utilidad neta
        $this->assertEqualsWithDelta(6_000_000.0,
            (float) $resp->json('data.operacion.utilidad_neta'),
            0.01);

        // Estructura básica presente
        $this->assertNotNull($resp->json('data.operacion'));
        $this->assertNotNull($resp->json('data.inversion'));
        $this->assertNotNull($resp->json('data.financiacion'));
    }

    public function test_feat_g_aporte_capital_va_a_financiacion(): void
    {
        $anio = 2041;
        $base = $this->setupBase($anio);

        // Aporte de capital: DB Bancos 8M / CR Capital 8M
        $this->crearAsientoAprobado(
            $base['periodos'][4],
            [
                ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '8000000', 'credito' => '0'],
                ['cuenta_id' => $base['cuentas']['capital']->id, 'debito' => '0', 'credito' => '8000000'],
            ],
            ['fecha' => "{$anio}-04-15"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/flujo-efectivo?año=' . $anio));

        $resp->assertOk();

        // El aporte capital (grupo 31) aparece como FLUJO POSITIVO en financiación
        $financiacion = collect($resp->json('data.financiacion.movimientos'));
        $aporteCapital = $financiacion->firstWhere('grupo', '31');

        $this->assertNotNull($aporteCapital, 'Grupo 31 (Capital) debe aparecer en financiación');
        $this->assertEqualsWithDelta(8_000_000.0,
            (float) $aporteCapital['flujo_caja'],
            0.01,
            'Aporte capital = entrada de caja 8M');

        $this->assertEqualsWithDelta(8_000_000.0,
            (float) $resp->json('data.financiacion.total'),
            0.01);
    }

    public function test_feat_g_compra_activo_fijo_va_a_inversion_como_negativo(): void
    {
        $anio = 2042;
        $base = $this->setupBase($anio);

        // Compra equipo: DB Activo Fijo 3M / CR Bancos 3M
        $this->crearAsientoAprobado(
            $base['periodos'][3],
            [
                ['cuenta_id' => $base['cuentas']['activo_fijo']->id, 'debito' => '3000000', 'credito' => '0'],
                ['cuenta_id' => $base['cuentas']['banco']->id,       'debito' => '0', 'credito' => '3000000'],
            ],
            ['fecha' => "{$anio}-03-15"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/flujo-efectivo?año=' . $anio));

        $resp->assertOk();

        $inversion = collect($resp->json('data.inversion.movimientos'));
        $ppe = $inversion->firstWhere('grupo', '15');

        $this->assertNotNull($ppe, 'Grupo 15 (PPE) debe aparecer en inversión');
        // Compra de activo: salida de caja → flujo negativo
        $this->assertEqualsWithDelta(-3_000_000.0,
            (float) $ppe['flujo_caja'],
            0.01,
            'Compra activo fijo = salida de caja -3M');

        $this->assertEqualsWithDelta(-3_000_000.0,
            (float) $resp->json('data.inversion.total'),
            0.01);
    }

    public function test_feat_g_aumento_cxc_reduce_caja_operacion(): void
    {
        $anio = 2043;
        $base = $this->setupBase($anio);

        // Venta a crédito: DB Clientes 2M / CR Ingreso 2M (no toca caja directamente)
        $this->crearAsientoAprobado(
            $base['periodos'][5],
            [
                ['cuenta_id' => $base['cuentas']['cliente']->id, 'debito' => '2000000', 'credito' => '0'],
                ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0', 'credito' => '2000000'],
            ],
            ['fecha' => "{$anio}-05-10"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/flujo-efectivo?año=' . $anio));

        $resp->assertOk();

        $cambios = collect($resp->json('data.operacion.cambios_capital_trabajo'));
        $cxc = $cambios->firstWhere('grupo', '13');

        $this->assertNotNull($cxc, 'Grupo 13 (CxC) debe aparecer en cambios capital trabajo');
        // Aumento en CxC = uso de caja (flujo negativo) por 2M
        $this->assertEqualsWithDelta(-2_000_000.0,
            (float) $cxc['flujo_caja'],
            0.01,
            'Aumento CxC = -2M (uso de caja)');

        // Utilidad 2M + cambio CxC -2M = 0 → flujo de operación 0
        $this->assertEqualsWithDelta(0.0,
            (float) $resp->json('data.operacion.total'),
            0.01,
            'Operación = utilidad 2M - aumento CxC 2M = 0 (la venta a crédito no genera caja real)');
    }

    public function test_feat_g_año_invalido_retorna_422(): void
    {
        $this->setupBase(2044);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/flujo-efectivo?año=1999'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/flujo-efectivo'))
            ->assertStatus(422);
    }
}
