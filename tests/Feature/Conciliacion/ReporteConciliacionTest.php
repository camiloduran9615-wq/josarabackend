<?php

declare(strict_types=1);

namespace Tests\Feature\Conciliacion;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-H: Reporte de Conciliación Bancaria (papel de trabajo).
 *
 * Valida:
 *  - Listado de consignaciones no registradas (líneas extracto crédito sin match)
 *  - Listado de cheques no cobrados (egresos del periodo sin match)
 *  - Notas débito banco no registradas (comisiones, GMF)
 *  - Cálculo de saldo libro al cierre del periodo
 *  - Conciliación matemática: saldo banco ajustado vs saldo libro ajustado
 */
class ReporteConciliacionTest extends TenantTestCase
{
    private User $contador;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupBase(): array
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'Conciliacion',
            'email'    => 'conc-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Cuenta de banco (111005)
        $cuentaBanco = CuentaContable::updateOrCreate(
            ['codigo' => '111005'],
            [
                'nombre'                => 'Bancos Test',
                'naturaleza'            => 'debito',
                'nivel'                 => 'subcuenta',
                'clase'                 => 1,
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

        // Periodo donde caerá el extracto
        $anio = 2050;
        $periodo = $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => 6]);

        return compact('cuentaBanco', 'periodo', 'anio');
    }

    /**
     * Crea un extracto bancario directamente en DB con las líneas indicadas.
     *
     * @param array<int, array{fecha:string, descripcion?:string, debito?:float, credito?:float, estado?:string}> $lineas
     */
    private function crearExtractoConLineas(string $periodoInicio, string $periodoFin, float $saldoInicial, float $saldoFinal, array $lineas): string
    {
        $extractoId = (string) Str::uuid();
        DB::table('extractos_bancarios')->insert([
            'id'              => $extractoId,
            'banco'           => 'Banco Test',
            'numero_cuenta'   => '0850-1234',
            'periodo_inicio'  => $periodoInicio,
            'periodo_fin'     => $periodoFin,
            'saldo_inicial'   => $saldoInicial,
            'saldo_final'     => $saldoFinal,
            'estado'          => 'importado',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        foreach ($lineas as $l) {
            DB::table('lineas_extracto')->insert([
                'id'                  => (string) Str::uuid(),
                'extracto_id'         => $extractoId,
                'fecha'               => $l['fecha'],
                'descripcion'         => $l['descripcion'] ?? '',
                'referencia'          => $l['referencia'] ?? '',
                'debito'              => $l['debito']  ?? 0,
                'credito'             => $l['credito'] ?? 0,
                'saldo'               => 0,
                'estado_conciliacion' => $l['estado'] ?? 'pendiente',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        return $extractoId;
    }

    public function test_feat_h_reporta_consignaciones_pendientes(): void
    {
        $base = $this->setupBase();

        $extractoId = $this->crearExtractoConLineas(
            '2050-06-01', '2050-06-30',
            saldoInicial: 5_000_000, saldoFinal: 7_500_000,
            lineas: [
                ['fecha' => '2050-06-05', 'descripcion' => 'CONSIGNACION CLIENTE A', 'credito' => 2_000_000],
                ['fecha' => '2050-06-15', 'descripcion' => 'CONSIGNACION CLIENTE B', 'credito' =>   500_000],
                ['fecha' => '2050-06-20', 'descripcion' => 'COMISION BANCARIA',      'debito'  =>    10_000],
            ],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl("/extractos-bancarios/{$extractoId}/reporte-conciliacion"));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        $cons = $resp->json('data.partidas_conciliatorias.consignaciones_no_registradas');
        $this->assertEqualsWithDelta(2_500_000.0, (float) $cons['total'], 0.01,
            'Total consignaciones = 2M + 500K');
        $this->assertCount(2, $cons['detalle']);

        $debitos = $resp->json('data.partidas_conciliatorias.notas_debito_banco_no_registradas');
        $this->assertEqualsWithDelta(10_000.0, (float) $debitos['total'], 0.01,
            'Comisión bancaria 10K');
    }

    public function test_feat_h_reporta_cheques_no_cobrados(): void
    {
        $base = $this->setupBase();

        // Crear un proveedor (tercero requerido por la FK comprobantes_egreso.tercero_id)
        $proveedorId = (string) Str::uuid();
        DB::table('terceros')->insert([
            'id'                          => $proveedorId,
            'identificacion_documento_id' => '31',
            'identificacion'              => '800999' . rand(100, 999),
            'razon_social'                => 'Proveedor X',
            'es_proveedor'                => true,
            'activo'                      => true,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        // Crear un comprobante de egreso del periodo sin conciliar
        $cheque1 = (string) Str::uuid();
        DB::table('comprobantes_egreso')->insert([
            'id'                => $cheque1,
            'tercero_id'        => $proveedorId,
            'numero'            => 'CE-001',
            'fecha'             => '2050-06-25',
            'concepto'          => 'Pago factura',
            'forma_pago'        => 'cheque',
            'cuenta_debito_id'  => $base['cuentaBanco']->id,
            'cuenta_credito_id' => $base['cuentaBanco']->id,
            'valor_pagado'      => 1_500_000,
            'estado'            => 'registrado',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $extractoId = $this->crearExtractoConLineas(
            '2050-06-01', '2050-06-30',
            saldoInicial: 5_000_000, saldoFinal: 5_000_000,
            lineas: [], // sin líneas, todas las que existían quedan pendientes
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl("/extractos-bancarios/{$extractoId}/reporte-conciliacion"));

        $resp->assertOk();

        $cheques = $resp->json('data.partidas_conciliatorias.cheques_no_cobrados');
        $detalle = collect($cheques['detalle'])->where('numero', 'CE-001')->first();

        $this->assertNotNull($detalle);
        $this->assertEqualsWithDelta(1_500_000.0, (float) $detalle['valor'], 0.01);
    }

    public function test_feat_h_conciliacion_matematica_con_cuenta_libro(): void
    {
        $base = $this->setupBase();

        // 1. Movimientos en libro: depósito inicial 5M, gasto 1M
        $this->crearAsientoAprobado(
            $base['periodo'],
            [
                ['cuenta_id' => $base['cuentaBanco']->id, 'debito' => '5000000', 'credito' => '0'],
                ['cuenta_id' => $this->crearCuenta(['naturaleza' => 'credito'])->id, 'debito' => '0', 'credito' => '5000000'],
            ],
            ['fecha' => '2050-06-01'],
        );
        $this->crearAsientoAprobado(
            $base['periodo'],
            [
                ['cuenta_id' => $this->crearCuenta(['naturaleza' => 'debito'])->id, 'debito' => '1000000', 'credito' => '0'],
                ['cuenta_id' => $base['cuentaBanco']->id, 'debito' => '0', 'credito' => '1000000'],
            ],
            ['fecha' => '2050-06-10'],
        );
        // Saldo libro al cierre = 5M - 1M = 4M

        // 2. Extracto: saldo banco 4.5M (incluye una consignación pendiente de 500K)
        $extractoId = $this->crearExtractoConLineas(
            '2050-06-01', '2050-06-30',
            saldoInicial: 5_000_000, saldoFinal: 4_500_000,
            lineas: [
                ['fecha' => '2050-06-25', 'descripcion' => 'CONSIGNACION', 'credito' => 500_000],
            ],
        );

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                "/extractos-bancarios/{$extractoId}/reporte-conciliacion?cuenta_id={$base['cuentaBanco']->id}"
            ));

        $resp->assertOk();

        // Saldo banco = 4.5M
        $this->assertEqualsWithDelta(4_500_000.0,
            (float) $resp->json('data.conciliacion_matematica.saldo_banco_segun_extracto'),
            0.01);

        // Saldo libro = 4M
        $this->assertEqualsWithDelta(4_000_000.0,
            (float) $resp->json('data.conciliacion_matematica.saldo_libro_segun_contabilidad'),
            0.01);

        // Banco ajustado = 4.5M (sin cheques no cobrados)
        $this->assertEqualsWithDelta(4_500_000.0,
            (float) $resp->json('data.conciliacion_matematica.saldo_banco_ajustado'),
            0.01);

        // Libro ajustado = 4M + 500K consignación pendiente = 4.5M
        $this->assertEqualsWithDelta(4_500_000.0,
            (float) $resp->json('data.conciliacion_matematica.saldo_libro_ajustado'),
            0.01);

        // Diferencia = 0 → CONCILIADO
        $this->assertEqualsWithDelta(0.0,
            (float) $resp->json('data.conciliacion_matematica.diferencia'),
            0.01);
        $this->assertTrue($resp->json('data.conciliacion_matematica.conciliado'));
    }

    public function test_feat_h_extracto_inexistente_retorna_404(): void
    {
        $this->setupBase();

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/extractos-bancarios/00000000-0000-0000-0000-000000000000/reporte-conciliacion'))
            ->assertStatus(404);
    }
}
