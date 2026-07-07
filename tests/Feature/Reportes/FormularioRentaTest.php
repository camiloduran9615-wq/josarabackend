<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use App\Services\Reportes\FormularioRentaService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-W: Formulario 110 DIAN — Declaración de Renta y Complementario.
 *
 * Valida los renglones prellenados desde la contabilidad: ingresos,
 * costos, deducciones, renta líquida, impuesto al 35% y retenciones
 * practicadas.
 */
class FormularioRentaTest extends TenantTestCase
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
            'apellido' => 'Renta',
            'email'    => 'renta-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        $codigo = sprintf('%04d-06', $anio);
        $periodo = PeriodoContable::where('codigo', $codigo)->first()
            ?? $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => 6]);

        $cuentas = [
            'banco'      => $this->getOrCreateCuenta('111505', 'Bancos F110',   'debito',  1),
            'cliente'    => $this->getOrCreateCuenta('130515', 'CxC F110',      'debito',  1),
            'retencion'  => $this->getOrCreateCuenta('135515', 'Retef anticipo', 'debito', 1),
            'proveedor'  => $this->getOrCreateCuenta('220515', 'Prov F110',     'credito', 2),
            'ingreso'    => $this->getOrCreateCuenta('413515', 'Ventas F110',   'credito', 4),
            'ing_no_op'  => $this->getOrCreateCuenta('421005', 'Intereses F110','credito', 4),
            'costo'      => $this->getOrCreateCuenta('613515', 'CMV F110',      'debito',  6),
            'gasto_adm'  => $this->getOrCreateCuenta('510515', 'Sueldos F110',  'debito',  5),
            'gasto_vts'  => $this->getOrCreateCuenta('520515', 'Comisiones F110','debito', 5),
            'gasto_fin'  => $this->getOrCreateCuenta('530505', 'Bancarios F110','debito',  5),
        ];

        return compact('periodo', 'cuentas');
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
                'clasificacion_balance' => $clase <= 2 ? 'corriente' : 'na',
                'clasificacion_pyg'     => $clase >= 4 ? 'operacional' : 'na',
                'sistema'               => true,
                'editable'              => false,
                'activo'                => true,
            ],
        );
    }

    public function test_feat_w_renta_calcula_renglones_basicos(): void
    {
        $anio = 2095;
        $base = $this->setupBase($anio);

        // Venta de 100M con retención del 2.5%
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['cliente']->id,   'debito' => '97500000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['retencion']->id, 'debito' => '2500000',  'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id,   'debito' => '0',        'credito' => '100000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        // Intereses ganados 1M (ingreso no operacional)
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['banco']->id,    'debito' => '1000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ing_no_op']->id,'debito' => '0',       'credito' => '1000000'],
        ], ['fecha' => "{$anio}-06-20"]);

        // Costo de ventas 60M
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['costo']->id,    'debito' => '60000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['proveedor']->id,'debito' => '0',        'credito' => '60000000'],
        ], ['fecha' => "{$anio}-06-16"]);

        // Gastos: admin 8M + ventas 5M + financiero 2M = 15M
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['gasto_adm']->id, 'debito' => '8000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['gasto_vts']->id, 'debito' => '5000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['gasto_fin']->id, 'debito' => '2000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['banco']->id,     'debito' => '0',       'credito' => '15000000'],
        ], ['fecha' => "{$anio}-06-25"]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/formulario-110?año=' . $anio));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.anio', $anio);
        $resp->assertJsonPath('data.tarifa', 0.35);

        $resumen = $resp->json('data.resumen');

        // ── INGRESOS ────────────────────────────────────────────────────────
        $this->assertEqualsWithDelta(100_000_000.0, $resumen['ingresos_operacionales'],    0.01);
        $this->assertEqualsWithDelta(  1_000_000.0, $resumen['ingresos_no_operacionales'], 0.01);
        $this->assertEqualsWithDelta(101_000_000.0, $resumen['total_ingresos_netos'],      0.01);

        // ── COSTOS ──────────────────────────────────────────────────────────
        $this->assertEqualsWithDelta(60_000_000.0, $resumen['costo_ventas'], 0.01);
        $this->assertEqualsWithDelta(60_000_000.0, $resumen['total_costos'], 0.01);

        // ── DEDUCCIONES ─────────────────────────────────────────────────────
        $this->assertEqualsWithDelta( 8_000_000.0, $resumen['gastos_administracion'],   0.01);
        $this->assertEqualsWithDelta( 5_000_000.0, $resumen['gastos_ventas'],           0.01);
        $this->assertEqualsWithDelta( 2_000_000.0, $resumen['gastos_no_operacionales'], 0.01);
        $this->assertEqualsWithDelta(15_000_000.0, $resumen['total_deducciones'],       0.01);

        // ── RENTA LÍQUIDA = 101M − 60M − 15M = 26M ──────────────────────────
        $this->assertEqualsWithDelta(26_000_000.0, $resumen['renta_liquida_ordinaria'], 0.01);
        $this->assertEqualsWithDelta(26_000_000.0, $resumen['renta_liquida_gravable'],  0.01);

        // ── IMPUESTO = 26M × 35% = 9'100.000 ────────────────────────────────
        $this->assertEqualsWithDelta(9_100_000.0, $resumen['impuesto_sobre_renta'], 0.01);
        $this->assertEqualsWithDelta(9_100_000.0, $resumen['total_impuesto_cargo'], 0.01);

        // ── RETENCIONES PRACTICADAS = 2.5M ──────────────────────────────────
        $this->assertEqualsWithDelta(2_500_000.0, $resumen['retenciones_practicadas'], 0.01);

        // ── SALDO A PAGAR = 9.1M − 2.5M = 6'600.000 ─────────────────────────
        $this->assertEqualsWithDelta(6_600_000.0, $resumen['saldo_a_pagar'], 0.01);
        $this->assertEqualsWithDelta(0.0,         $resumen['saldo_a_favor'], 0.01);
    }

    public function test_feat_w_renta_genera_saldo_a_favor_cuando_retenciones_exceden(): void
    {
        $anio = 2096;
        $base = $this->setupBase($anio);

        // Venta pequeña con retención muy alta (caso teórico)
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['cliente']->id,   'debito' => '5000000',  'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['retencion']->id, 'debito' => '10000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id,   'debito' => '0',        'credito' => '15000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        // Costos altos que dejan renta líquida pequeña
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['costo']->id,     'debito' => '10000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['proveedor']->id, 'debito' => '0',        'credito' => '10000000'],
        ], ['fecha' => "{$anio}-06-16"]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/formulario-110?año=' . $anio));

        $resp->assertOk();

        $resumen = $resp->json('data.resumen');

        // Renta líquida = 15M − 10M = 5M
        $this->assertEqualsWithDelta(5_000_000.0, $resumen['renta_liquida_gravable'], 0.01);
        // Impuesto = 5M × 35% = 1.75M
        $this->assertEqualsWithDelta(1_750_000.0, $resumen['total_impuesto_cargo'], 0.01);
        // Retenciones 10M > impuesto 1.75M → saldo a favor = 8.25M
        $this->assertEqualsWithDelta(8_250_000.0, $resumen['saldo_a_favor'], 0.01);
        $this->assertEqualsWithDelta(0.0,         $resumen['saldo_a_pagar'], 0.01);
    }

    public function test_feat_w_renta_acepta_tarifa_personalizada(): void
    {
        $anio = 2097;
        $base = $this->setupBase($anio);

        // Renta líquida = 10M
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '10000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0',        'credito' => '10000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        // Aplicar tarifa preferencial 9% (zona franca art. 240-1 ET)
        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/formulario-110?año=' . $anio . '&tarifa=0.09'));

        $resp->assertOk();
        $resp->assertJsonPath('data.tarifa', 0.09);

        // Impuesto = 10M × 9% = 900.000
        $this->assertEqualsWithDelta(900_000.0,
            (float) $resp->json('data.resumen.impuesto_sobre_renta'),
            0.01);
    }

    public function test_feat_w_renta_año_invalido_retorna_422(): void
    {
        $this->setupBase(2098);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/formulario-110?año=1999'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/formulario-110'))
            ->assertStatus(422);
    }

    public function test_feat_w_renglones_estructura_completa(): void
    {
        $anio = 2099;
        $base = $this->setupBase($anio);

        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '10000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0',        'credito' => '10000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/formulario-110?año=' . $anio));

        $resp->assertOk();

        $renglones = $resp->json('data.renglones');

        // Renglones críticos del Formulario 110 que deben existir
        foreach ([32, 33, 38, 41, 42, 44, 45, 46, 49, 50, 54, 57, 61, 65, 67] as $r) {
            $this->assertArrayHasKey((string) $r, $renglones,
                "Renglón {$r} debe existir en el Formulario 110");
            $this->assertArrayHasKey('titulo', $renglones[$r]);
            $this->assertArrayHasKey('valor',  $renglones[$r]);
        }

        // Advertencia obligatoria sobre ajustes manuales del contador
        $this->assertStringContainsString('contador',
            (string) $resp->json('data.advertencia'));
    }

    public function test_feat_w_service_tarifa_general_es_35_porciento(): void
    {
        $this->assertSame(0.35, FormularioRentaService::TARIFA_GENERAL);
        $this->assertSame(0.15, FormularioRentaService::TARIFA_GANANCIA);
    }
}
