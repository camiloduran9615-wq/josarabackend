<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Factura;
use App\Models\Tenant\PeriodoContable;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-AA/AB: Dashboard Ejecutivo — KPIs gerenciales.
 *
 * Valida la estructura del endpoint y la lógica de cada bloque:
 *   • YTD vs año anterior (variación)
 *   • Aging de cartera (rangos correctos)
 *   • Top 5 clientes
 *   • Liquidez (clase 11)
 *   • Indicadores (margen bruto/neto, días de cartera)
 *   • Impuestos pendientes
 *   • Alertas operacionales
 */
class DashboardEjecutivoTest extends TenantTestCase
{
    private User $contador;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupBase(): array
    {
        $this->contador = User::create([
            'nombre'   => 'Gerente',
            'apellido' => 'Dashboard',
            'email'    => 'dashej-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        $anio   = (int) date('Y');
        $mes    = (int) date('m');
        $codigo = sprintf('%04d-%02d', $anio, $mes);

        $periodo = PeriodoContable::where('codigo', $codigo)->first()
            ?? $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => $mes]);

        $cuentas = [
            'caja'      => $this->getOrCreateCuenta('110505', 'Caja DE',     'debito',  1),
            'banco'     => $this->getOrCreateCuenta('111005', 'Bancos DE',   'debito',  1),
            'cliente'   => $this->getOrCreateCuenta('130505', 'CxC DE',      'debito',  1),
            'iva'       => $this->getOrCreateCuenta('240805', 'IVA DE',      'credito', 2),
            'ingreso'   => $this->getOrCreateCuenta('413505', 'Ventas DE',   'credito', 4),
            'costo'     => $this->getOrCreateCuenta('613505', 'CMV DE',      'debito',  6),
            'gasto'     => $this->getOrCreateCuenta('510505', 'Sueldos DE',  'debito',  5),
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

    public function test_dashboard_ejecutivo_estructura_completa(): void
    {
        $this->setupBase();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/dashboard-ejecutivo'));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        // Top-level keys
        foreach (['fecha_corte', 'periodo', 'ytd', 'aging_cartera', 'top_clientes',
                  'liquidez', 'indicadores', 'impuestos_pendientes', 'alertas', 'meta'] as $key) {
            $resp->assertJsonPath("data.{$key}", fn ($v) => $v !== null);
        }

        // YTD tiene actual + anterior + variacion
        foreach (['actual', 'anterior', 'variacion'] as $b) {
            $this->assertArrayHasKey($b, $resp->json('data.ytd'));
        }

        // Aging tiene los 5 rangos
        foreach (['corriente', 'rango_1_30', 'rango_31_60', 'rango_61_90', 'rango_mas_90'] as $r) {
            $this->assertArrayHasKey($r, $resp->json('data.aging_cartera'));
        }

        // Indicadores
        foreach (['margen_bruto_pct', 'margen_neto_pct', 'dias_cartera'] as $i) {
            $this->assertArrayHasKey($i, $resp->json('data.indicadores'));
        }
    }

    public function test_dashboard_ejecutivo_calcula_ytd_y_margenes(): void
    {
        $base = $this->setupBase();
        $hoy  = Carbon::today();

        // Venta del año: 100M ingresos + 19M IVA
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['cliente']->id, 'debito' => '119000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0',         'credito' => '100000000'],
            ['cuenta_id' => $base['cuentas']['iva']->id,     'debito' => '0',         'credito' => '19000000'],
        ], ['fecha' => $hoy->toDateString()]);

        // Costo: 60M
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['costo']->id, 'debito' => '60000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['banco']->id, 'debito' => '0',        'credito' => '60000000'],
        ], ['fecha' => $hoy->toDateString()]);

        // Gasto: 15M
        $this->crearAsientoAprobado($base['periodo'], [
            ['cuenta_id' => $base['cuentas']['gasto']->id, 'debito' => '15000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['banco']->id, 'debito' => '0',        'credito' => '15000000'],
        ], ['fecha' => $hoy->toDateString()]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/dashboard-ejecutivo'));
        $resp->assertOk();

        $ytd = $resp->json('data.ytd.actual');
        $this->assertEqualsWithDelta(100_000_000.0, $ytd['ingresos'], 0.01);
        $this->assertEqualsWithDelta( 60_000_000.0, $ytd['costos'],   0.01);
        $this->assertEqualsWithDelta( 15_000_000.0, $ytd['gastos'],   0.01);
        $this->assertEqualsWithDelta( 25_000_000.0, $ytd['utilidad'], 0.01);

        // Margen bruto = (100-60)/100 = 40%
        $this->assertEqualsWithDelta(40.0,
            (float) $resp->json('data.indicadores.margen_bruto_pct'), 0.01);
        // Margen neto = 25/100 = 25%
        $this->assertEqualsWithDelta(25.0,
            (float) $resp->json('data.indicadores.margen_neto_pct'), 0.01);

        // Impuestos pendientes: IVA por pagar = 19M (saldo crédito de 2408)
        $this->assertEqualsWithDelta(19_000_000.0,
            (float) $resp->json('data.impuestos_pendientes.iva_por_pagar'), 0.01);
    }

    public function test_dashboard_ejecutivo_aging_clasifica_facturas_por_antiguedad(): void
    {
        $this->setupBase();
        $hoy = Carbon::today();

        $cliente = Tercero::create([
            'tipo_persona'               => 'juridica',
            'tipo_documento'             => 'nit',
            'numero_documento'           => '800111222',
            'identificacion_documento_id'=> '31',
            'identificacion'             => '800111222-1',
            'razon_social'               => 'Cliente Aging',
            'email'                      => 'aging@test.co',
            'es_cliente'                 => true,
            'activo'                     => true,
        ]);

        // 4 facturas en diferentes rangos de aging
        $crearFactura = function (string $vence, float $valor) use ($cliente) {
            return Factura::create([
                'tipo_documento'      => 'FV',
                'estado'              => 'validado',
                'tercero_id'          => $cliente->id,
                'fecha_emision'       => $vence,
                'payment_due_date'    => $vence,
                'reference_code'      => 'AGE-' . Str::random(8),
                'numbering_range_id'  => 0,
                'payment_form'        => '1',
                'payment_method_code' => '10',
                'valor_bruto'         => $valor,
                'valor_impuestos'     => 0,
                'valor_descuentos'    => 0,
                'valor_retenciones'   => 0,
                'valor_total'         => $valor,
            ]);
        };

        // Corriente (vence hoy o futuro)
        $crearFactura($hoy->copy()->addDays(5)->toDateString(),       1_000_000);
        // 1-30 (vencida hace 15 días)
        $crearFactura($hoy->copy()->subDays(15)->toDateString(),      2_000_000);
        // 31-60 (vencida hace 45 días)
        $crearFactura($hoy->copy()->subDays(45)->toDateString(),      4_000_000);
        // 61-90 (vencida hace 75 días)
        $crearFactura($hoy->copy()->subDays(75)->toDateString(),      8_000_000);
        // +90 (vencida hace 120 días)
        $crearFactura($hoy->copy()->subDays(120)->toDateString(),    16_000_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/dashboard-ejecutivo'));
        $resp->assertOk();

        $aging = $resp->json('data.aging_cartera');
        $this->assertEqualsWithDelta( 1_000_000.0, $aging['corriente'],    0.01);
        $this->assertEqualsWithDelta( 2_000_000.0, $aging['rango_1_30'],   0.01);
        $this->assertEqualsWithDelta( 4_000_000.0, $aging['rango_31_60'],  0.01);
        $this->assertEqualsWithDelta( 8_000_000.0, $aging['rango_61_90'],  0.01);
        $this->assertEqualsWithDelta(16_000_000.0, $aging['rango_mas_90'], 0.01);
        $this->assertEqualsWithDelta(31_000_000.0, $aging['total'],        0.01);
        $this->assertSame(5, $aging['cantidad']);

        // Alerta de facturas vencidas (>30 días) debe encender — son 3 facturas
        $tipos = collect($resp->json('data.alertas'))->pluck('tipo')->all();
        $this->assertContains('facturas_vencidas_30', $tipos);
    }

    public function test_dashboard_ejecutivo_top_clientes_ordena_por_ventas(): void
    {
        $this->setupBase();
        $hoy = Carbon::today();

        $crearCliente = function (string $nit, string $nombre) {
            return Tercero::create([
                'tipo_persona'               => 'juridica',
                'tipo_documento'             => 'nit',
                'numero_documento'           => $nit,
                'identificacion_documento_id'=> '31',
                'identificacion'             => $nit . '-' . rand(0, 9),
                'razon_social'               => $nombre,
                'email'                      => Str::random(5) . '@test.co',
                'es_cliente'                 => true,
                'activo'                     => true,
            ]);
        };

        $c1 = $crearCliente('800100001', 'Cliente Alfa');
        $c2 = $crearCliente('800100002', 'Cliente Beta');

        $crearFactura = function (Tercero $c, float $valor) use ($hoy) {
            Factura::create([
                'tipo_documento'      => 'FV',
                'estado'              => 'validado',
                'tercero_id'          => $c->id,
                'fecha_emision'       => $hoy->toDateString(),
                'reference_code'      => 'TOP-' . Str::random(8),
                'numbering_range_id'  => 0,
                'payment_form'        => '1',
                'payment_method_code' => '10',
                'valor_bruto'         => $valor,
                'valor_impuestos'     => 0,
                'valor_descuentos'    => 0,
                'valor_retenciones'   => 0,
                'valor_total'         => $valor,
            ]);
        };

        // Alfa: 50M (3 facturas), Beta: 30M (2 facturas)
        $crearFactura($c1, 20_000_000);
        $crearFactura($c1, 20_000_000);
        $crearFactura($c1, 10_000_000);
        $crearFactura($c2, 20_000_000);
        $crearFactura($c2, 10_000_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/dashboard-ejecutivo'));
        $resp->assertOk();

        $top = $resp->json('data.top_clientes');
        $this->assertGreaterThanOrEqual(2, count($top));

        // Primer cliente debe ser Alfa con 50M
        $this->assertSame('Cliente Alfa', $top[0]['nombre']);
        $this->assertEqualsWithDelta(50_000_000.0, (float) $top[0]['total'], 0.01);
        $this->assertSame(3, $top[0]['facturas']);

        // Segundo cliente Beta con 30M
        $this->assertSame('Cliente Beta', $top[1]['nombre']);
        $this->assertEqualsWithDelta(30_000_000.0, (float) $top[1]['total'], 0.01);
    }

    public function test_dashboard_ejecutivo_alerta_periodos_sin_cerrar(): void
    {
        $this->setupBase();

        // Crear un periodo abierto vencido (más de 30 días)
        $periodoVencido = $this->crearPeriodo([
            'año_fiscal' => 2024,
            'mes'        => 1,
        ]);
        $periodoVencido->estado = 'abierto';
        $periodoVencido->save();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/dashboard-ejecutivo'));
        $resp->assertOk();

        $tipos = collect($resp->json('data.alertas'))->pluck('tipo')->all();
        $this->assertContains('periodos_sin_cerrar', $tipos);
    }
}
