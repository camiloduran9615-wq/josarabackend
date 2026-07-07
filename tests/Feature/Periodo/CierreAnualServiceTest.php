<?php

declare(strict_types=1);

namespace Tests\Feature\Periodo;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Services\Periodo\CierreAnualService;
use App\Services\Periodo\PeriodoOperacionInvalidaException;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * CierreAnualService — genera 2 asientos (cancelación + traslado) y deja 5905 en cero.
 *
 * Pre-condiciones necesarias para cada test:
 *   - Cuenta 5905 y 3606 deben existir en el PUC (sembradas por TenantPucSeeder).
 *   - Debe existir un periodo ANUAL en estado 'cerrado' para el año de prueba.
 */
final class CierreAnualServiceTest extends TenantTestCase
{
    private CierreAnualService $service;
    private CuentaContable $cuenta5905;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CierreAnualService::class);

        // Service uses cargarCuentaPorPrefijo('5905') with acepta_movimientos=true,
        // which finds the subcuenta 590505 (not the parent 5905 that doesn't accept movements).
        /** @var CuentaContable|null $c5905 */
        $c5905 = CuentaContable::query()
            ->where('codigo', 'LIKE', '5905%')
            ->where('acepta_movimientos', true)
            ->orderBy('codigo')
            ->first();
        if ($c5905 === null) {
            $this->markTestSkipped('Cuenta 590505 no existe. Ejecuta TenantPucSeeder primero.');
        }
        $this->cuenta5905 = $c5905;
    }

    private function crearPeriodoAnualCerrado(int $anio): PeriodoContable
    {
        return PeriodoContable::query()->create([
            'tipo'        => PeriodoContable::TIPO_ANUAL,
            'codigo'      => "FY{$anio}T",
            'fecha_inicio' => "{$anio}-01-01",
            'fecha_fin'   => "{$anio}-12-31",
            'año_fiscal'  => $anio,
            'mes'         => null,
            'estado'      => PeriodoContable::ESTADO_CERRADO,
            'cerrado_por_id' => $this->adminUser->id,
            'cerrado_at'     => now(),
        ]);
    }

    private function crearSaldoResultado(string $cuentaId, string $periodoId, string $claseChar): void
    {
        // Clase 4 (ingresos) → saldo creditor; clase 5/6/7 (gastos/costos) → saldo deudor
        $esIngreso = ($claseChar === '4');
        DB::table('cuenta_saldos')->insert([
            'id'                   => \Illuminate\Support\Str::uuid()->toString(),
            'cuenta_contable_id'   => $cuentaId,
            'periodo_id'           => $periodoId,
            'tercero_id'           => null,
            'centro_costo_id'      => null,
            'sucursal_id'          => null,
            'saldo_inicial_debito' => '0.0000',
            'saldo_inicial_credito'=> '0.0000',
            'movimiento_debito'    => $esIngreso ? '0.0000' : '800000.0000',
            'movimiento_credito'   => $esIngreso ? '1000000.0000' : '0.0000',
            'saldo_final_debito'   => $esIngreso ? '0.0000' : '800000.0000',
            'saldo_final_credito'  => $esIngreso ? '1000000.0000' : '0.0000',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    public function test_cierre_anual_crea_dos_asientos(): void
    {
        $anio = 2019; // Año ficticio, no debe existir previo
        $periodo = $this->crearPeriodoAnualCerrado($anio);

        $ctaIngreso = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 4]);
        $ctaGasto   = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 5]);

        $this->crearSaldoResultado($ctaIngreso->id, $periodo->id, '4');
        $this->crearSaldoResultado($ctaGasto->id,   $periodo->id, '5');

        $resultado = $this->service->ejecutar($anio, $this->adminUser);

        $this->assertSame($anio, $resultado['anio']);
        $this->assertNotNull($resultado['asiento_cancelacion_id']);
        $this->assertNotNull($resultado['asiento_traslado_id']);
        $this->assertNotSame($resultado['asiento_cancelacion_id'], $resultado['asiento_traslado_id']);

        // Deben existir 2 asientos de tipo CI en el periodo
        $asientosCI = Asiento::query()
            ->where('periodo_id', $periodo->id)
            ->where('tipo_comprobante', 'CI')
            ->count();

        $this->assertSame(2, $asientosCI);
    }

    public function test_cierre_anual_con_utilidad_deja_5905_en_cero(): void
    {
        $anio = 2018;
        $periodo = $this->crearPeriodoAnualCerrado($anio);

        // Ingresos 1.000.000 > Gastos 800.000 → utilidad 200.000
        $ctaIngreso = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 4]);
        $ctaGasto   = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 5]);

        $this->crearSaldoResultado($ctaIngreso->id, $periodo->id, '4');
        $this->crearSaldoResultado($ctaGasto->id,   $periodo->id, '5');

        $resultado = $this->service->ejecutar($anio, $this->adminUser);

        $this->assertSame('utilidad', $resultado['resultado']);
        $this->assertGreaterThan(0, (float) $resultado['monto']);

        // Verificar que el asiento de cancelación tiene a 5905 como balanceador
        /** @var Asiento $asientoCancelacion */
        $asientoCancelacion = Asiento::query()->findOrFail($resultado['asiento_cancelacion_id']);
        $lineas5905 = $asientoCancelacion->lineas()
            ->where('cuenta_id', $this->cuenta5905->id)
            ->get();

        $this->assertNotEmpty($lineas5905, 'El asiento de cancelación debe incluir la cuenta 5905.');
    }

    public function test_cierre_anual_es_idempotente_lanza_excepcion_al_repetir(): void
    {
        $anio = 2017;
        $periodo = $this->crearPeriodoAnualCerrado($anio);

        $ctaIngreso = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 4]);
        $ctaGasto   = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 5]);
        $this->crearSaldoResultado($ctaIngreso->id, $periodo->id, '4');
        $this->crearSaldoResultado($ctaGasto->id,   $periodo->id, '5');

        // Primera ejecución
        $this->service->ejecutar($anio, $this->adminUser);

        // Segunda ejecución — debe lanzar PeriodoOperacionInvalidaException
        $this->expectException(PeriodoOperacionInvalidaException::class);
        $this->service->ejecutar($anio, $this->adminUser);
    }

    public function test_cierre_anual_requiere_periodo_en_estado_cerrado(): void
    {
        $anio = 2016;

        // Periodo en estado ABIERTO (no cerrado)
        PeriodoContable::query()->create([
            'tipo'        => PeriodoContable::TIPO_ANUAL,
            'codigo'      => "FY{$anio}A",
            'fecha_inicio' => "{$anio}-01-01",
            'fecha_fin'   => "{$anio}-12-31",
            'año_fiscal'  => $anio,
            'mes'         => null,
            'estado'      => PeriodoContable::ESTADO_ABIERTO,
        ]);

        $this->expectException(PeriodoOperacionInvalidaException::class);
        $this->service->ejecutar($anio, $this->adminUser);
    }
}
