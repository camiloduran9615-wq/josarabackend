<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-P: Notas a los Estados Financieros (NIC 1.117).
 *
 * Valida desglose por cuenta para cada nota con comparativo año anterior.
 */
class NotasEstadosFinancierosTest extends TenantTestCase
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
            'apellido' => 'Notas',
            'email'    => 'notas-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Periodos necesarios (junio del año actual y del anterior para comparativo)
        $periodos = [];
        foreach ([$anio - 1, $anio] as $a) {
            $codigo = sprintf('%04d-06', $a);
            $periodos[$a] = PeriodoContable::where('codigo', $codigo)->first()
                ?? $this->crearPeriodo(['año_fiscal' => $a, 'mes' => 6]);
        }

        $cuentas = [
            'banco'       => $this->getOrCreateCuenta('111505', 'Bancos NEF', 'debito', 1),
            'cliente'     => $this->getOrCreateCuenta('130515', 'CxC NEF',    'debito', 1),
            'proveedor'   => $this->getOrCreateCuenta('220515', 'Proveedores NEF', 'credito', 2),
            'capital'     => $this->getOrCreateCuenta('310515', 'Capital NEF', 'credito', 3),
            'ingreso'     => $this->getOrCreateCuenta('413515', 'Ventas NEF', 'credito', 4),
            'gasto'       => $this->getOrCreateCuenta('510515', 'Sueldos NEF', 'debito', 5),
        ];

        return compact('periodos', 'cuentas');
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

    public function test_feat_p_genera_notas_estructuradas(): void
    {
        $anio = 2090;
        $base = $this->setupBase($anio);

        // Movimientos: aporte 5M en año anterior, venta 3M en año actual
        $this->crearAsientoAprobado($base['periodos'][$anio - 1], [
            ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '5000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['capital']->id, 'debito' => '0', 'credito' => '5000000'],
        ], ['fecha' => ($anio - 1) . '-06-15']);

        $this->crearAsientoAprobado($base['periodos'][$anio], [
            ['cuenta_id' => $base['cuentas']['cliente']->id, 'debito' => '3000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0', 'credito' => '3000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/notas-estados-financieros?año=' . $anio));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.anio', $anio);

        $notas = collect($resp->json('data.notas'));
        $this->assertGreaterThanOrEqual(10, $notas->count(), 'Al menos 10 notas generadas');

        // Cada nota debe tener estructura: numero, titulo, cuentas[], totales
        $primera = $notas->first();
        $this->assertArrayHasKey('numero', $primera);
        $this->assertArrayHasKey('titulo', $primera);
        $this->assertArrayHasKey('cuentas', $primera);
        $this->assertArrayHasKey('total_actual', $primera);
        $this->assertArrayHasKey('total_anterior', $primera);
        $this->assertArrayHasKey('variacion', $primera);
    }

    public function test_feat_p_nota_efectivo_acumula_movimientos_anteriores(): void
    {
        $anio = 2091;
        $base = $this->setupBase($anio);

        // Año anterior: aporte de capital en efectivo 8M
        $this->crearAsientoAprobado($base['periodos'][$anio - 1], [
            ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '8000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['capital']->id, 'debito' => '0', 'credito' => '8000000'],
        ], ['fecha' => ($anio - 1) . '-06-15']);

        // Año actual: aporte adicional 2M
        $this->crearAsientoAprobado($base['periodos'][$anio], [
            ['cuenta_id' => $base['cuentas']['banco']->id,   'debito' => '2000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['capital']->id, 'debito' => '0', 'credito' => '2000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/notas-estados-financieros?año=' . $anio));

        $resp->assertOk();

        $notas = collect($resp->json('data.notas'));
        $nota4 = $notas->firstWhere('numero', 4);

        $this->assertNotNull($nota4);
        $this->assertEquals('Efectivo y Equivalentes de Efectivo', $nota4['titulo']);

        // Saldo actual de efectivo = 8M + 2M = 10M
        $this->assertEqualsWithDelta(10_000_000.0,
            (float) $nota4['total_actual'],
            0.01,
            'Saldo actual = saldo año anterior + movimientos del año actual');

        // Saldo anterior = 8M
        $this->assertEqualsWithDelta(8_000_000.0,
            (float) $nota4['total_anterior'],
            0.01);

        // Variación = +2M
        $this->assertEqualsWithDelta(2_000_000.0,
            (float) $nota4['variacion'],
            0.01);

        // Variación porcentual = 25%
        $this->assertEqualsWithDelta(25.0,
            (float) $nota4['variacion_pct'],
            0.01);
    }

    public function test_feat_p_nota_ingresos_solo_acumula_ano_actual(): void
    {
        $anio = 2092;
        $base = $this->setupBase($anio);

        // Año anterior: venta 5M
        $this->crearAsientoAprobado($base['periodos'][$anio - 1], [
            ['cuenta_id' => $base['cuentas']['cliente']->id, 'debito' => '5000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0', 'credito' => '5000000'],
        ], ['fecha' => ($anio - 1) . '-08-15']);

        // Año actual: venta 7M
        $this->crearAsientoAprobado($base['periodos'][$anio], [
            ['cuenta_id' => $base['cuentas']['cliente']->id, 'debito' => '7000000', 'credito' => '0'],
            ['cuenta_id' => $base['cuentas']['ingreso']->id, 'debito' => '0', 'credito' => '7000000'],
        ], ['fecha' => "{$anio}-08-15"]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/notas-estados-financieros?año=' . $anio));

        $resp->assertOk();

        $notas = collect($resp->json('data.notas'));
        $notaIngresos = $notas->firstWhere('numero', 13);

        $this->assertNotNull($notaIngresos);

        // Ingresos año actual = 7M (no acumula 12M)
        $this->assertEqualsWithDelta(7_000_000.0,
            (float) $notaIngresos['total_actual'],
            0.01,
            'Cuentas de resultado solo acumulan el año actual');

        // Ingresos año anterior = 5M
        $this->assertEqualsWithDelta(5_000_000.0,
            (float) $notaIngresos['total_anterior'],
            0.01);

        // Variación = +2M
        $this->assertEqualsWithDelta(2_000_000.0,
            (float) $notaIngresos['variacion'],
            0.01);
    }

    public function test_feat_p_año_invalido_retorna_422(): void
    {
        $this->setupBase(2093);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/notas-estados-financieros?año=1999'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/notas-estados-financieros'))
            ->assertStatus(422);
    }
}
