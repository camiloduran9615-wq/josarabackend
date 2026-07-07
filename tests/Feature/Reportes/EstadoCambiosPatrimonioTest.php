<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-F: Estado de Cambios en el Patrimonio (NIC 1).
 *
 * Reporta movimientos sobre cuentas de patrimonio (clase 3) en el año fiscal:
 *  - Saldo inicial (acumulado de periodos previos)
 *  - Aumentos (créditos del año — aportes, utilidades retenidas, etc.)
 *  - Disminuciones (débitos del año — dividendos, pérdidas)
 *  - Saldo final
 *
 * Agrupado por categoría: Capital (31), Reservas (33), Resultados del
 * ejercicio (36), Resultados acumulados (37), etc.
 */
class EstadoCambiosPatrimonioTest extends TenantTestCase
{
    private User $contador;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupFixtures(int $anio): array
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'ECP',
            'email'    => 'ecp-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Cuentas de patrimonio
        $cuentaCapital = $this->getOrCreateCuenta('310505', 'Capital Social', 'credito', 3);
        $cuentaReserva = $this->getOrCreateCuenta('330505', 'Reserva Legal', 'credito', 3);
        $cuentaUtilEj  = $this->getOrCreateCuenta('360505', 'Utilidad del Ejercicio', 'credito', 3);
        $cuentaUtilAc  = $this->getOrCreateCuenta('370505', 'Utilidades Acumuladas', 'credito', 3);

        // Periodos para el año
        $periodos = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $codigo = sprintf('%04d-%02d', $anio, $mes);
            if (PeriodoContable::where('codigo', $codigo)->doesntExist()) {
                $periodos[$mes] = $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => $mes]);
            } else {
                $periodos[$mes] = PeriodoContable::where('codigo', $codigo)->first();
            }
        }

        return [
            'cuentas'   => compact('cuentaCapital', 'cuentaReserva', 'cuentaUtilEj', 'cuentaUtilAc'),
            'periodos'  => $periodos,
        ];
    }

    private function getOrCreateCuenta(string $codigo, string $nombre, string $naturaleza, int $clase): CuentaContable
    {
        // updateOrCreate (no firstOrCreate) para garantizar la clase aunque
        // el PUC seed la haya sembrado con clase=null en cuentas existentes.
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
                'clasificacion_balance' => 'no_corriente',
                'clasificacion_pyg'     => 'na',
                'sistema'               => true,
                'editable'              => false,
                'activo'                => true,
            ],
        );
    }

    public function test_feat_f_reporta_aumentos_disminuciones_por_categoria(): void
    {
        $anio = 2032;
        $fix = $this->setupFixtures($anio);

        // Para que aparezca en el reporte necesitamos asientos REALES sobre
        // estas cuentas. Usamos un par de asientos balanceados:
        $cuentaBanco = $this->getOrCreateCuenta('111105', 'Bancos Test', 'debito', 1);

        // Asiento 1: aumento de capital (mes marzo)
        // DB Bancos 5M / CR Capital 5M
        $this->crearAsientoAprobado(
            $fix['periodos'][3],
            [
                ['cuenta_id' => $cuentaBanco->id,            'debito' => '5000000', 'credito' => '0'],
                ['cuenta_id' => $fix['cuentas']['cuentaCapital']->id, 'debito' => '0', 'credito' => '5000000'],
            ],
            ['fecha' => "{$anio}-03-15"],
        );

        // Asiento 2: utilidad del ejercicio (mes diciembre)
        // DB algún resultado / CR utilidad 1.5M — para el ECP solo importa la CR
        $cuentaResultado = $this->getOrCreateCuenta('590505', 'Ganancias y Pérdidas Test', 'credito', 5);
        $this->crearAsientoAprobado(
            $fix['periodos'][12],
            [
                ['cuenta_id' => $cuentaResultado->id, 'debito' => '1500000', 'credito' => '0'],
                ['cuenta_id' => $fix['cuentas']['cuentaUtilEj']->id, 'debito' => '0', 'credito' => '1500000'],
            ],
            ['fecha' => "{$anio}-12-31"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/estado-cambios-patrimonio?año=' . $anio));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.anio', $anio);

        $categorias = collect($resp->json('data.categorias'));
        $this->assertGreaterThanOrEqual(2, $categorias->count(),
            'Esperaba al menos 2 categorías: Capital + Resultados del Ejercicio');

        // Buscar la categoría Capital (código 31)
        $capital = $categorias->firstWhere('codigo', '31');
        $this->assertNotNull($capital, 'Categoría Capital (31) debe estar presente');
        $this->assertEqualsWithDelta(5_000_000.0,
            (float) $capital['aumentos'],
            0.01,
            'Aumento Capital = 5.000.000');

        // Resultados del Ejercicio (36)
        $resultados = $categorias->firstWhere('codigo', '36');
        $this->assertNotNull($resultados);
        $this->assertEqualsWithDelta(1_500_000.0,
            (float) $resultados['aumentos'],
            0.01,
            'Utilidad del ejercicio = 1.500.000');

        // Totales
        $this->assertEqualsWithDelta(6_500_000.0,
            (float) $resp->json('data.totales.aumentos'),
            0.01,
            'Aumentos totales = 5M + 1.5M = 6.5M');

        $this->assertEqualsWithDelta(0.0,
            (float) $resp->json('data.totales.disminuciones'),
            0.01);
    }

    public function test_feat_f_disminucion_por_distribucion_dividendos(): void
    {
        $anio = 2033;
        $fix = $this->setupFixtures($anio);

        // Distribuir utilidades acumuladas (clase 3): DB utilidades acumuladas / CR cxp accionistas
        // Esto es una DISMINUCIÓN del patrimonio.
        $cxpAccionistas = $this->getOrCreateCuenta('236805', 'CxP Accionistas Test', 'credito', 2);

        $this->crearAsientoAprobado(
            $fix['periodos'][6],
            [
                ['cuenta_id' => $fix['cuentas']['cuentaUtilAc']->id, 'debito' => '800000', 'credito' => '0'],
                ['cuenta_id' => $cxpAccionistas->id, 'debito' => '0', 'credito' => '800000'],
            ],
            ['fecha' => "{$anio}-06-15"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/estado-cambios-patrimonio?año=' . $anio));

        $resp->assertOk();

        $categorias = collect($resp->json('data.categorias'));
        $acumuladas = $categorias->firstWhere('codigo', '37');

        $this->assertNotNull($acumuladas, 'Categoría Resultados Acumulados (37) debe estar presente');
        $this->assertEqualsWithDelta(800_000.0,
            (float) $acumuladas['disminuciones'],
            0.01,
            'Distribución de utilidades = disminución 800K');

        $this->assertEqualsWithDelta(0.0,
            (float) $acumuladas['aumentos'],
            0.01);

        $this->assertEqualsWithDelta(800_000.0,
            (float) $resp->json('data.totales.disminuciones'),
            0.01);
    }

    public function test_feat_f_saldo_inicial_acumula_periodos_anteriores(): void
    {
        $anio = 2034;
        $fix = $this->setupFixtures($anio);
        $fixAnt = $this->setupFixtures($anio - 1); // crear periodos del año anterior

        $cuentaBanco = $this->getOrCreateCuenta('111105', 'Bancos Test', 'debito', 1);

        // Año anterior: simulamos saldo cerrado en cuenta_saldos directamente.
        // crearAsientoAprobado usa withoutEvents, lo que no dispara
        // ActualizarSaldosListener y deja cuenta_saldos sin materializar.
        // Para este test sobre saldo inicial, insertamos el saldo cerrado
        // directamente — equivalente al snapshot tras cerrar el periodo.
        $this->crearSaldo(
            $fix['cuentas']['cuentaCapital'],
            $fixAnt['periodos'][12],   // saldo "al cierre" del año anterior
            [
                'movimiento_credito' => '3000000.0000',
                'saldo_final_credito' => '3000000.0000',
            ],
        );

        // Año actual: aporte adicional 2M (movimiento ordinario)
        $this->crearAsientoAprobado(
            $fix['periodos'][3],
            [
                ['cuenta_id' => $cuentaBanco->id, 'debito' => '2000000', 'credito' => '0'],
                ['cuenta_id' => $fix['cuentas']['cuentaCapital']->id, 'debito' => '0', 'credito' => '2000000'],
            ],
            ['fecha' => "{$anio}-03-15"],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/estado-cambios-patrimonio?año=' . $anio));

        $resp->assertOk();

        $capital = collect($resp->json('data.categorias'))->firstWhere('codigo', '31');

        // Saldo inicial = 3M (del año anterior)
        $this->assertEqualsWithDelta(3_000_000.0,
            (float) $capital['saldo_inicial'],
            0.01,
            'Saldo inicial del año = saldo final del año anterior');

        // Aumentos = 2M (solo del año actual)
        $this->assertEqualsWithDelta(2_000_000.0,
            (float) $capital['aumentos'],
            0.01,
            'Aumentos solo del año actual');

        // Saldo final = 3M + 2M = 5M
        $this->assertEqualsWithDelta(5_000_000.0,
            (float) $capital['saldo_final'],
            0.01);
    }

    public function test_feat_f_año_invalido_retorna_422(): void
    {
        $this->setupFixtures(2035);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/estado-cambios-patrimonio?año=1999'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/estado-cambios-patrimonio'))
            ->assertStatus(422);
    }
}
