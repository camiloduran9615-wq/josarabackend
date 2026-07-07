<?php

declare(strict_types=1);

namespace Tests\Feature\ActivosFijos;

use App\Models\Tenant\ActivoFijo;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\DepreciacionMensual;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-E: Módulo Activos Fijos + Depreciación (NIC 16).
 *
 * Valida:
 *  - CRUD básico de activos fijos
 *  - Cálculo de depreciación mensual (línea recta)
 *  - Generación de asiento contable consolidado
 *  - Idempotencia: no se deprecia dos veces el mismo mes
 *  - No se deprecia más allá del costo - residual
 */
class ActivosFijosTest extends TenantTestCase
{
    private User $contador;
    private CuentaContable $cuentaActivo;
    private CuentaContable $cuentaDepAcum;
    private CuentaContable $cuentaGasto;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupFixtures(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'AF',
            'email'    => 'af-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Garantizar las 3 cuentas (clase 1 activo, clase 1 contra-activo, clase 5 gasto)
        $this->cuentaActivo = $this->getOrCreateCuenta('152410', 'Equipo de Cómputo', 'debito', 1);
        $this->cuentaDepAcum = $this->getOrCreateCuenta('159210', 'Depreciación Acum. Equipo Cómputo', 'credito', 1);
        $this->cuentaGasto = $this->getOrCreateCuenta('516010', 'Gasto Depreciación Equipo Cómputo', 'debito', 5);

        // Asegurar periodo del mes actual (necesario para depreciar)
        $anio = (int) date('Y');
        $mes  = (int) date('m');
        $codigo = sprintf('%04d-%02d', $anio, $mes);
        if (PeriodoContable::where('codigo', $codigo)->doesntExist()) {
            $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => $mes]);
        }
    }

    private function getOrCreateCuenta(string $codigo, string $nombre, string $naturaleza, int $clase): CuentaContable
    {
        return CuentaContable::firstOrCreate(
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

    public function test_crear_activo_fijo_via_http(): void
    {
        $this->setupFixtures();

        $payload = [
            'codigo'                           => 'AF-PC-001',
            'descripcion'                      => 'Computador Dell Latitude 5440',
            'categoria'                        => 'equipo_computo',
            'costo_adquisicion'                => 3_600_000,
            'fecha_adquisicion'                => '2026-01-15',
            'vida_util_meses'                  => 36,       // 3 años
            'valor_residual'                   => 0,
            'cuenta_activo_id'                 => $this->cuentaActivo->id,
            'cuenta_depreciacion_acumulada_id' => $this->cuentaDepAcum->id,
            'cuenta_gasto_depreciacion_id'     => $this->cuentaGasto->id,
        ];

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/activos-fijos'), $payload);

        $resp->assertStatus(201);
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.codigo', 'AF-PC-001');
        $resp->assertJsonPath('data.estado', 'activo');
        // depreciacion_mensual = 3.600.000 / 36 = 100.000
        $activoModel = ActivoFijo::where('codigo', 'AF-PC-001')->firstOrFail();
        $this->assertEqualsWithDelta(100_000.0, $activoModel->depreciacionMensual(), 0.01);
        $this->assertEqualsWithDelta(3_600_000.0, $activoModel->valorNeto(), 0.01,
            'Valor neto inicial = costo (sin depreciación aún)');
    }

    public function test_depreciar_genera_asiento_balanceado(): void
    {
        $this->setupFixtures();

        // Activo de $1.200.000 a 12 meses = depreciación mensual $100.000
        $activo = ActivoFijo::create([
            'codigo'                            => 'AF-T-' . Str::random(4),
            'descripcion'                       => 'Equipo Test',
            'categoria'                         => 'equipo_computo',
            'costo_adquisicion'                 => 1_200_000,
            'fecha_adquisicion'                 => now()->subMonth()->toDateString(),
            'fecha_inicio_depreciacion'         => now()->subMonth()->toDateString(),
            'vida_util_meses'                   => 12,
            'valor_residual'                    => 0,
            'cuenta_activo_id'                  => $this->cuentaActivo->id,
            'cuenta_depreciacion_acumulada_id'  => $this->cuentaDepAcum->id,
            'cuenta_gasto_depreciacion_id'      => $this->cuentaGasto->id,
            'estado'                            => 'activo',
        ]);

        $anio = (int) now()->year;
        $mes  = (int) now()->month;

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl("/activos-fijos/depreciar/{$anio}/{$mes}"));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.activos_procesados', 1);
        $resp->assertJsonPath('data.total_depreciado', 100000);
        $this->assertNotNull($resp->json('data.asiento_id'));

        // Asiento generado
        $asiento = Asiento::with('lineas')->findOrFail($resp->json('data.asiento_id'));
        $this->assertEquals('DP', $asiento->tipo_comprobante);
        $this->assertEquals('aprobado', $asiento->estado);

        $db = round((float) $asiento->lineas->sum('debito'), 2);
        $cr = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals(100_000.0, $db, 'DB depreciación = 100.000');
        $this->assertEquals(100_000.0, $cr, 'CR depreciación acumulada = 100.000');
        $this->assertEquals($db, $cr, 'Asiento balanceado');

        // Depreciación acumulada del activo actualizada
        $activo->refresh();
        $this->assertEqualsWithDelta(100_000.0, (float) $activo->depreciacion_acumulada, 0.01);

        // Movimiento DepreciacionMensual registrado
        $this->assertEquals(1, DepreciacionMensual::where('activo_fijo_id', $activo->id)
            ->where('anio', $anio)->where('mes', $mes)->count());
    }

    public function test_depreciar_es_idempotente_por_activo_mes(): void
    {
        $this->setupFixtures();

        $activo = ActivoFijo::create([
            'codigo'                            => 'AF-IDEM-' . Str::random(4),
            'descripcion'                       => 'Test idempotente',
            'categoria'                         => 'equipo_computo',
            'costo_adquisicion'                 => 600_000,
            'fecha_adquisicion'                 => now()->subMonths(2)->toDateString(),
            'fecha_inicio_depreciacion'         => now()->subMonths(2)->toDateString(),
            'vida_util_meses'                   => 12,
            'valor_residual'                    => 0,
            'cuenta_activo_id'                  => $this->cuentaActivo->id,
            'cuenta_depreciacion_acumulada_id'  => $this->cuentaDepAcum->id,
            'cuenta_gasto_depreciacion_id'      => $this->cuentaGasto->id,
            'estado'                            => 'activo',
        ]);

        $anio = (int) now()->year;
        $mes  = (int) now()->month;

        // Primera ejecución → procesa
        $r1 = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl("/activos-fijos/depreciar/{$anio}/{$mes}"))
            ->assertOk();
        $this->assertEquals(1, $r1->json('data.activos_procesados'));

        // Segunda ejecución del mismo mes → salta (idempotente)
        $r2 = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl("/activos-fijos/depreciar/{$anio}/{$mes}"))
            ->assertOk();
        $this->assertEquals(0, $r2->json('data.activos_procesados'));
        $this->assertEquals(1, $r2->json('data.activos_saltados'));

        // Solo 1 movimiento persistido
        $this->assertEquals(1, DepreciacionMensual::where('activo_fijo_id', $activo->id)
            ->where('anio', $anio)->where('mes', $mes)->count());
    }

    public function test_depreciar_respeta_valor_residual(): void
    {
        $this->setupFixtures();

        // Activo de 1M con vida_util 12 meses y residual 200K
        // Depreciación mensual = (1M - 200K) / 12 = 66.666,67
        // Pero ya tiene 750K acumulado → pendiente = 800K - 750K = 50K
        // Próxima depreciación: min(66.666, 50K) = 50K
        $activo = ActivoFijo::create([
            'codigo'                            => 'AF-RESIDUAL-' . Str::random(4),
            'descripcion'                       => 'Test residual',
            'categoria'                         => 'equipo_computo',
            'costo_adquisicion'                 => 1_000_000,
            'fecha_adquisicion'                 => now()->subMonths(11)->toDateString(),
            'fecha_inicio_depreciacion'         => now()->subMonths(11)->toDateString(),
            'vida_util_meses'                   => 12,
            'valor_residual'                    => 200_000,
            'depreciacion_acumulada'            => 750_000,  // ya muy depreciado
            'cuenta_activo_id'                  => $this->cuentaActivo->id,
            'cuenta_depreciacion_acumulada_id'  => $this->cuentaDepAcum->id,
            'cuenta_gasto_depreciacion_id'      => $this->cuentaGasto->id,
            'estado'                            => 'activo',
        ]);

        $anio = (int) now()->year;
        $mes  = (int) now()->month;

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl("/activos-fijos/depreciar/{$anio}/{$mes}"))
            ->assertOk();

        $activo->refresh();

        // Acumulada final = 800K (no debe pasar de costo - residual = 800K)
        $this->assertEqualsWithDelta(800_000.0, (float) $activo->depreciacion_acumulada, 0.01,
            'Depreciación nunca supera costo - residual');

        // El movimiento del mes es 50K (no la cuota completa)
        $mov = DepreciacionMensual::where('activo_fijo_id', $activo->id)->first();
        $this->assertEqualsWithDelta(50_000.0, (float) $mov->valor_depreciacion, 0.01,
            'Última cuota = remanente, no la cuota mensual completa');

        // Valor neto contable = residual
        $this->assertEqualsWithDelta(200_000.0, $activo->valorNeto(), 0.01);
    }

    public function test_depreciar_omite_activos_vendidos_o_baja(): void
    {
        $this->setupFixtures();

        ActivoFijo::create([
            'codigo'                            => 'AF-VEND-' . Str::random(4),
            'descripcion'                       => 'Vendido',
            'categoria'                         => 'equipo_computo',
            'costo_adquisicion'                 => 500_000,
            'fecha_adquisicion'                 => now()->subMonths(3)->toDateString(),
            'fecha_inicio_depreciacion'         => now()->subMonths(3)->toDateString(),
            'vida_util_meses'                   => 12,
            'valor_residual'                    => 0,
            'cuenta_activo_id'                  => $this->cuentaActivo->id,
            'cuenta_depreciacion_acumulada_id'  => $this->cuentaDepAcum->id,
            'cuenta_gasto_depreciacion_id'      => $this->cuentaGasto->id,
            'estado'                            => 'vendido', // ← no debe depreciar
        ]);

        $anio = (int) now()->year;
        $mes  = (int) now()->month;

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl("/activos-fijos/depreciar/{$anio}/{$mes}"))
            ->assertOk();

        $this->assertEquals(0, $resp->json('data.activos_procesados'));
        $this->assertEqualsWithDelta(0.0, (float) $resp->json('data.total_depreciado'), 0.01);
    }

    public function test_depreciar_mes_invalido_retorna_422(): void
    {
        $this->setupFixtures();

        $anio = (int) now()->year;
        $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl("/activos-fijos/depreciar/{$anio}/13"))
            ->assertStatus(422);
    }

    public function test_crear_activo_con_categoria_invalida_retorna_422(): void
    {
        $this->setupFixtures();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/activos-fijos'), [
                'codigo'                           => 'AF-INV',
                'descripcion'                      => 'X',
                'categoria'                        => 'no_existe',
                'costo_adquisicion'                => 100_000,
                'fecha_adquisicion'                => '2026-01-01',
                'vida_util_meses'                  => 12,
                'cuenta_activo_id'                 => $this->cuentaActivo->id,
                'cuenta_depreciacion_acumulada_id' => $this->cuentaDepAcum->id,
                'cuenta_gasto_depreciacion_id'     => $this->cuentaGasto->id,
            ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['categoria']);
    }
}
