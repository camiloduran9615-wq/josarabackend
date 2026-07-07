<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-O: Descargar Estados Financieros NIIF a CSV.
 */
class EstadosFinancierosCsvTest extends TenantTestCase
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
            'apellido' => 'CSV-EF',
            'email'    => 'efcsv-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Periodo + cuentas para movimientos
        $codigo = sprintf('%04d-06', $anio);
        $periodo = PeriodoContable::where('codigo', $codigo)->first()
            ?? $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => 6]);

        $banco = CuentaContable::updateOrCreate(['codigo' => '111105'], [
            'nombre' => 'Bancos Test',          'naturaleza' => 'debito',
            'nivel' => 'subcuenta', 'clase' => 1, 'acepta_movimientos' => true,
            'exige_tercero' => false, 'exige_centro_costo' => false,
            'exige_base_impuesto' => false, 'clasificacion_balance' => 'corriente',
            'clasificacion_pyg' => 'na', 'sistema' => true, 'editable' => false, 'activo' => true,
        ]);
        $capital = CuentaContable::updateOrCreate(['codigo' => '310505'], [
            'nombre' => 'Capital Social Test', 'naturaleza' => 'credito',
            'nivel' => 'subcuenta', 'clase' => 3, 'acepta_movimientos' => true,
            'exige_tercero' => false, 'exige_centro_costo' => false,
            'exige_base_impuesto' => false, 'clasificacion_balance' => 'no_corriente',
            'clasificacion_pyg' => 'na', 'sistema' => true, 'editable' => false, 'activo' => true,
        ]);
        $ingreso = CuentaContable::updateOrCreate(['codigo' => '413505'], [
            'nombre' => 'Ventas',              'naturaleza' => 'credito',
            'nivel' => 'subcuenta', 'clase' => 4, 'acepta_movimientos' => true,
            'exige_tercero' => false, 'exige_centro_costo' => false,
            'exige_base_impuesto' => false, 'clasificacion_balance' => 'na',
            'clasificacion_pyg' => 'operacional', 'sistema' => true, 'editable' => false, 'activo' => true,
        ]);

        // 1 asiento de aporte + 1 venta
        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $banco->id,   'debito' => '10000000', 'credito' => '0'],
            ['cuenta_id' => $capital->id, 'debito' => '0', 'credito' => '10000000'],
        ], ['fecha' => "{$anio}-06-01"]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $banco->id,   'debito' => '3000000', 'credito' => '0'],
            ['cuenta_id' => $ingreso->id, 'debito' => '0', 'credito' => '3000000'],
        ], ['fecha' => "{$anio}-06-15"]);

        return compact('periodo', 'banco', 'capital', 'ingreso', 'anio');
    }

    public function test_feat_o_csv_balance_general_descarga(): void
    {
        $base = $this->setupBase(2080);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/balance-general?fecha_corte=2080-12-31'));

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('balance-general-2080-12-31.csv',
            $resp->headers->get('Content-Disposition') ?? '');

        $body = $resp->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'BOM UTF-8');
        $this->assertStringContainsString('seccion|grupo_codigo|grupo_nombre|codigo|nombre|saldo', $body,
            'Headers BG presentes');
        $this->assertStringContainsString('Total Activo', $body, 'Línea total presente');
    }

    public function test_feat_o_csv_estado_resultados_descarga(): void
    {
        $base = $this->setupBase(2081);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/estado-resultados?desde=2081-01-01&hasta=2081-12-31'));

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $body = $resp->getContent();
        $this->assertStringContainsString('Utilidad Neta del Ejercicio', $body);
    }

    public function test_feat_o_csv_estado_cambios_patrimonio_descarga(): void
    {
        $base = $this->setupBase(2082);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/estado-cambios-patrimonio?año=2082'));

        $resp->assertOk();
        $body = $resp->getContent();
        $this->assertStringContainsString('categoria_codigo|categoria|codigo_cuenta', $body);
        $this->assertStringContainsString('PATRIMONIO TOTAL', $body);
    }

    public function test_feat_o_csv_flujo_efectivo_descarga(): void
    {
        $base = $this->setupBase(2083);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/flujo-efectivo?año=2083'));

        $resp->assertOk();
        $body = $resp->getContent();
        $this->assertStringContainsString('actividad|codigo_grupo|rubro|flujo_caja', $body);
        $this->assertStringContainsString('OPERACION', $body);
        $this->assertStringContainsString('FINANCIACION', $body);
    }

    public function test_feat_o_csv_balance_comprobacion_descarga(): void
    {
        $base = $this->setupBase(2084);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/balance-comprobacion?periodo_id=' . $base['periodo']->id));

        $resp->assertOk();
        $body = $resp->getContent();
        // 14 columnas en headers
        $this->assertStringContainsString('codigo|nombre|clase|naturaleza', $body);
        $this->assertStringContainsString('sa_debito|sa_credito', $body);
    }

    public function test_feat_o_csv_validaciones_de_parametros(): void
    {
        $this->setupBase(2085);

        $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/balance-general'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/estado-resultados?desde=2085-12-31&hasta=2085-01-01'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/csv/flujo-efectivo?año=1999'))
            ->assertStatus(422);
    }
}
