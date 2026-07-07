<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\Factura;
use App\Models\Tenant\FacturaRetencion;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-I/J/K: Información Exógena DIAN — medios magnéticos.
 *
 * Valida los 3 formatos más usados:
 *  - 1001 Pagos y retenciones a terceros (proveedores)
 *  - 1003 Retenciones que nos practicaron (clientes)
 *  - 1007 Ingresos recibidos por tercero (clientes)
 */
class InformacionExogenaTest extends TenantTestCase
{
    private User $contador;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupBase(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'Exogena',
            'email'    => 'exo-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);
    }

    private function crearTercero(string $tipo, string $nit, string $razon): Tercero
    {
        return Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => $nit,
            'identificacion_documento_id' => '31',
            'identificacion'              => $nit . '-' . rand(0, 9),
            'dv'                          => '1',
            'razon_social'                => $razon,
            'municipio_id'                => '11001',
            'es_cliente'                  => $tipo === 'cliente',
            'es_proveedor'                => $tipo === 'proveedor',
            'activo'                      => true,
        ]);
    }

    private function crearCompra(Tercero $proveedor, string $fecha, float $base, float $iva = 0, float $retef = 0, float $reteica = 0): void
    {
        DocumentoIngreso::create([
            'tercero_id'                 => $proveedor->id,
            'numero'                     => 'ING-' . Str::random(6),
            'tipo'                       => 'factura_compra',
            'fecha'                      => $fecha,
            'concepto'                   => 'Test exógena',
            'forma_pago'                 => 'credito',
            'valor_bruto'                => $base,
            'valor_iva'                  => $iva,
            'valor_retefuente'           => $retef,
            'valor_reteica'              => $reteica,
            'valor_reteiva'              => 0,
            'valor_total'                => $base + $iva - $retef - $reteica,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-' . Str::random(6),
        ]);
    }

    // ─── FORMATO 1001 ─────────────────────────────────────────────────────────

    public function test_feat_i_formato_1001_agrupa_por_tercero(): void
    {
        $this->setupBase();
        $p1 = $this->crearTercero('proveedor', '800100100', 'Proveedor A SAS');
        $p2 = $this->crearTercero('proveedor', '800100200', 'Proveedor B SAS');

        // Proveedor A: 2 compras en 2060
        $this->crearCompra($p1, '2060-03-15', base: 2_000_000, iva: 380_000, retef: 50_000, reteica: 8_280);
        $this->crearCompra($p1, '2060-06-20', base: 1_000_000, iva: 190_000, retef: 25_000);
        // Proveedor B: 1 compra en 2060
        $this->crearCompra($p2, '2060-09-10', base: 5_000_000, iva: 950_000, retef: 125_000, reteica: 20_700);
        // Compra FUERA del año
        $this->crearCompra($p1, '2059-12-30', base: 999_000_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1001?año=2060'));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('data.formato', 1001);
        $resp->assertJsonPath('data.anio', 2060);

        // Totales
        $this->assertEqualsWithDelta(8_000_000.0,
            (float) $resp->json('data.totales.base_total'),
            0.01,
            'Base = 2M + 1M + 5M = 8M');
        $this->assertEqualsWithDelta(200_000.0,
            (float) $resp->json('data.totales.retefuente_total'),
            0.01,
            'Retefuente = 50K + 25K + 125K = 200K');
        $this->assertEqualsWithDelta(28_980.0,
            (float) $resp->json('data.totales.reteica_total'),
            0.01,
            'ReteICA = 8.280 + 20.700');

        // 2 terceros distintos
        $this->assertEquals(2, $resp->json('data.totales.num_terceros'));

        // 3 documentos (1 fuera del año excluido)
        $this->assertEquals(3, $resp->json('data.totales.num_documentos'));

        // Verifica que cada registro tiene los campos requeridos por DIAN
        $registros = collect($resp->json('data.registros'));
        $this->assertNotEmpty($registros);
        $primero = $registros->first();
        $this->assertArrayHasKey('tipo_documento', $primero);
        $this->assertArrayHasKey('identificacion', $primero);
        $this->assertArrayHasKey('dv', $primero);
        $this->assertArrayHasKey('razon_social', $primero);
        $this->assertArrayHasKey('retefuente', $primero);
    }

    public function test_feat_i_formato_1001_excluye_documentos_anulados(): void
    {
        $this->setupBase();
        $p = $this->crearTercero('proveedor', '800200200', 'Proveedor C');

        $this->crearCompra($p, '2061-05-10', base: 1_000_000, retef: 25_000);
        // Anular un documento
        DocumentoIngreso::where('tercero_id', $p->id)->first()->forceFill(['estado' => 'anulado'])->save();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1001?año=2061'));

        $resp->assertOk();
        $this->assertEquals(0, $resp->json('data.totales.num_documentos'),
            'Documentos anulados NO deben aparecer en exógena');
    }

    // ─── FORMATO 1003 ─────────────────────────────────────────────────────────

    public function test_feat_j_formato_1003_consolida_retenciones_que_nos_practicaron(): void
    {
        $this->setupBase();
        $cliente = $this->crearTercero('cliente', '890900900', 'Gran Contribuyente SA');

        // Crear factura + retención registrada
        $facturaId = (string) Str::uuid();
        DB::table('facturas')->insert([
            'id'             => $facturaId,
            'tipo_documento' => 'FV',
            'fecha_emision'  => '2062-04-15',
            'tercero_id'     => $cliente->id,
            'reference_code' => 'F-EXO-1003',
            'payment_form'   => '1',
            'payment_method_code' => '10',
            'valor_bruto'         => 5_000_000,
            'valor_impuestos'     => 950_000,
            'valor_retenciones'   => 125_000,
            'valor_descuentos'    => 0,
            'valor_total'         => 5_825_000,
            'estado'              => 'borrador',
            'numero'              => '',
            'numero_completo'     => '',
            'prefijo'             => '',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        FacturaRetencion::create([
            'factura_id' => $facturaId,
            'codigo'     => '05',
            'nombre'     => 'Retefuente 2.5%',
            'tasa'       => 2.5,
            'valor'      => 125_000,
            'base'       => 5_000_000,
        ]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1003?año=2062'));

        $resp->assertOk();
        $resp->assertJsonPath('data.formato', 1003);

        $registros = collect($resp->json('data.registros'));
        $this->assertEquals(1, $registros->count());

        $reg = $registros->first();
        $this->assertEquals('890900900-' . substr($cliente->identificacion, -1), $reg['identificacion']);
        $this->assertEqualsWithDelta(125_000.0, (float) $reg['valor_retenido'], 0.01);
        $this->assertEqualsWithDelta(5_000_000.0, (float) $reg['base'], 0.01);

        $this->assertEqualsWithDelta(125_000.0,
            (float) $resp->json('data.totales.retenido_total'),
            0.01);
    }

    // ─── FORMATO 1007 ─────────────────────────────────────────────────────────

    public function test_feat_k_formato_1007_consolida_ingresos_por_cliente(): void
    {
        $this->setupBase();
        $cli1 = $this->crearTercero('cliente', '890700100', 'Cliente Grande');
        $cli2 = $this->crearTercero('cliente', '890700200', 'Cliente Pequeño');

        // 2 facturas a cliente 1
        foreach ([1_000_000, 2_000_000] as $i => $monto) {
            DB::table('facturas')->insert([
                'id'             => (string) Str::uuid(),
                'tipo_documento' => 'FV',
                'fecha_emision'  => '2063-0' . ($i + 5) . '-10',
                'tercero_id'     => $cli1->id,
                'reference_code' => 'F-1007-' . Str::random(5),
                'payment_form'   => '1',
                'payment_method_code' => '10',
                'valor_bruto'    => $monto,
                'valor_impuestos'=> $monto * 0.19,
                'valor_retenciones' => 0,
                'valor_descuentos' => 0,
                'valor_total'    => $monto + ($monto * 0.19),
                'estado'         => 'borrador',
                'numero'         => '',
                'numero_completo'=> '',
                'prefijo'        => '',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
        // 1 factura a cliente 2
        DB::table('facturas')->insert([
            'id'             => (string) Str::uuid(),
            'tipo_documento' => 'FV',
            'fecha_emision'  => '2063-08-20',
            'tercero_id'     => $cli2->id,
            'reference_code' => 'F-1007-' . Str::random(5),
            'payment_form'   => '1',
            'payment_method_code' => '10',
            'valor_bruto'    => 500_000,
            'valor_impuestos'=> 95_000,
            'valor_retenciones' => 0,
            'valor_descuentos' => 0,
            'valor_total'    => 595_000,
            'estado'         => 'borrador',
            'numero'         => '',
            'numero_completo'=> '',
            'prefijo'        => '',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1007?año=2063'));

        $resp->assertOk();
        $resp->assertJsonPath('data.formato', 1007);

        $registros = collect($resp->json('data.registros'));
        $this->assertEquals(2, $registros->count(), 'Dos clientes con ingresos en 2063');

        // Totales
        $this->assertEqualsWithDelta(3_500_000.0,
            (float) $resp->json('data.totales.base_total'),
            0.01,
            'Base = 1M + 2M + 500K = 3.5M');
        $this->assertEqualsWithDelta(665_000.0,
            (float) $resp->json('data.totales.iva_total'),
            0.01,
            'IVA = 190K + 380K + 95K = 665K');

        $this->assertEquals(3, $resp->json('data.totales.num_facturas'));

        // Primer registro debe ser el cliente con mayor monto (ordenado desc)
        $primero = $registros->first();
        $this->assertEquals('Cliente Grande', $primero['razon_social']);
    }

    // ─── FORMATO 1005 (IVA descontable) ──────────────────────────────────────

    public function test_feat_l_formato_1005_iva_descontable_por_proveedor(): void
    {
        $this->setupBase();
        $p = $this->crearTercero('proveedor', '800500700', 'Proveedor IVA');

        $this->crearCompra($p, '2064-03-10', base: 1_000_000, iva: 190_000);
        $this->crearCompra($p, '2064-08-15', base:   500_000, iva:  95_000);
        // Compra SIN IVA → no debe aparecer en 1005
        $this->crearCompra($p, '2064-09-01', base:   200_000, iva: 0);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1005?año=2064'));

        $resp->assertOk();
        $resp->assertJsonPath('data.formato', 1005);

        $registros = collect($resp->json('data.registros'));
        $this->assertEquals(1, $registros->count());

        $reg = $registros->first();
        $this->assertEqualsWithDelta(1_500_000.0, (float) $reg['base_gravada'], 0.01,
            'Base gravada = 1M + 500K (la 3ra sin IVA no cuenta)');
        $this->assertEqualsWithDelta(285_000.0, (float) $reg['iva_descontable'], 0.01,
            'IVA descontable = 190K + 95K');
        $this->assertEquals(2, $reg['num_documentos']);

        $this->assertEqualsWithDelta(285_000.0,
            (float) $resp->json('data.totales.iva_descontable_total'),
            0.01);
    }

    // ─── FORMATO 1006 (IVA generado) ─────────────────────────────────────────

    public function test_feat_l_formato_1006_iva_generado_por_cliente(): void
    {
        $this->setupBase();
        $cli = $this->crearTercero('cliente', '890600500', 'Cliente IVA Gen');

        foreach ([1_000_000, 500_000] as $monto) {
            DB::table('facturas')->insert([
                'id'                  => (string) Str::uuid(),
                'tipo_documento'      => 'FV',
                'fecha_emision'       => '2065-05-15',
                'tercero_id'          => $cli->id,
                'reference_code'      => 'F-1006-' . Str::random(5),
                'payment_form'        => '1',
                'payment_method_code' => '10',
                'valor_bruto'         => $monto,
                'valor_impuestos'     => $monto * 0.19,
                'valor_retenciones'   => 0,
                'valor_descuentos'    => 0,
                'valor_total'         => $monto + ($monto * 0.19),
                'estado'              => 'borrador',
                'numero'              => '',
                'numero_completo'     => '',
                'prefijo'             => '',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1006?año=2065'));

        $resp->assertOk();
        $resp->assertJsonPath('data.formato', 1006);

        $reg = collect($resp->json('data.registros'))->first();
        $this->assertEqualsWithDelta(1_500_000.0, (float) $reg['base_gravada'], 0.01);
        $this->assertEqualsWithDelta(285_000.0, (float) $reg['iva_generado'], 0.01);

        $this->assertEqualsWithDelta(285_000.0,
            (float) $resp->json('data.totales.iva_generado_total'),
            0.01);
    }

    // ─── FORMATO 1008 (CxC al cierre) ────────────────────────────────────────

    public function test_feat_m_formato_1008_saldos_cxc_al_cierre(): void
    {
        $this->setupBase();
        $cliente = $this->crearTercero('cliente', '890800800', 'Cliente Deudor');

        $cuentaCxc = \App\Models\Tenant\CuentaContable::updateOrCreate(
            ['codigo' => '130510'],
            [
                'nombre' => 'CxC Test 1008', 'naturaleza' => 'debito',
                'nivel' => 'subcuenta', 'clase' => 1, 'acepta_movimientos' => true,
                'exige_tercero' => false, 'exige_centro_costo' => false,
                'exige_base_impuesto' => false, 'clasificacion_balance' => 'corriente',
                'clasificacion_pyg' => 'na', 'sistema' => true, 'editable' => false, 'activo' => true,
            ],
        );
        $cuentaIngreso = \App\Models\Tenant\CuentaContable::updateOrCreate(
            ['codigo' => '413510'],
            [
                'nombre' => 'Ingresos Test 1008', 'naturaleza' => 'credito',
                'nivel' => 'subcuenta', 'clase' => 4, 'acepta_movimientos' => true,
                'exige_tercero' => false, 'exige_centro_costo' => false,
                'exige_base_impuesto' => false, 'clasificacion_balance' => 'na',
                'clasificacion_pyg' => 'operacional', 'sistema' => true, 'editable' => false, 'activo' => true,
            ],
        );

        $periodo = \App\Models\Tenant\PeriodoContable::where('codigo', '2066-06')->first()
            ?? $this->crearPeriodo(['año_fiscal' => 2066, 'mes' => 6]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cuentaCxc->id,     'debito' => '2500000', 'credito' => '0', 'tercero_id' => $cliente->id],
            ['cuenta_id' => $cuentaIngreso->id, 'debito' => '0', 'credito' => '2500000'],
        ], ['fecha' => '2066-06-15']);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1008?año=2066'));

        $resp->assertOk();
        $resp->assertJsonPath('data.formato', 1008);

        $reg = collect($resp->json('data.registros'))->firstWhere('razon_social', 'Cliente Deudor');
        $this->assertNotNull($reg, 'Cliente con saldo CxC debe aparecer');
        $this->assertEqualsWithDelta(2_500_000.0, (float) $reg['saldo_cxc'], 0.01);
    }

    // ─── FORMATO 1009 (CxP al cierre) ────────────────────────────────────────

    public function test_feat_m_formato_1009_saldos_cxp_al_cierre(): void
    {
        $this->setupBase();
        $prov = $this->crearTercero('proveedor', '800900900', 'Proveedor Acreedor');

        $cuentaCxp = \App\Models\Tenant\CuentaContable::updateOrCreate(
            ['codigo' => '220510'],
            [
                'nombre' => 'CxP Test 1009', 'naturaleza' => 'credito',
                'nivel' => 'subcuenta', 'clase' => 2, 'acepta_movimientos' => true,
                'exige_tercero' => false, 'exige_centro_costo' => false,
                'exige_base_impuesto' => false, 'clasificacion_balance' => 'corriente',
                'clasificacion_pyg' => 'na', 'sistema' => true, 'editable' => false, 'activo' => true,
            ],
        );
        $cuentaGasto = \App\Models\Tenant\CuentaContable::updateOrCreate(
            ['codigo' => '513510'],
            [
                'nombre' => 'Gasto Test 1009', 'naturaleza' => 'debito',
                'nivel' => 'subcuenta', 'clase' => 5, 'acepta_movimientos' => true,
                'exige_tercero' => false, 'exige_centro_costo' => false,
                'exige_base_impuesto' => false, 'clasificacion_balance' => 'na',
                'clasificacion_pyg' => 'operacional', 'sistema' => true, 'editable' => false, 'activo' => true,
            ],
        );

        $periodo = \App\Models\Tenant\PeriodoContable::where('codigo', '2067-06')->first()
            ?? $this->crearPeriodo(['año_fiscal' => 2067, 'mes' => 6]);

        $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cuentaGasto->id, 'debito' => '1800000', 'credito' => '0'],
            ['cuenta_id' => $cuentaCxp->id,   'debito' => '0', 'credito' => '1800000', 'tercero_id' => $prov->id],
        ], ['fecha' => '2067-06-15']);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/exogena-1009?año=2067'));

        $resp->assertOk();
        $resp->assertJsonPath('data.formato', 1009);

        $reg = collect($resp->json('data.registros'))->firstWhere('razon_social', 'Proveedor Acreedor');
        $this->assertNotNull($reg);
        $this->assertEqualsWithDelta(1_800_000.0, (float) $reg['saldo_cxp'], 0.01);
    }

    public function test_exogena_anio_invalido_retorna_422(): void
    {
        $this->setupBase();

        foreach (['1001', '1003', '1005', '1006', '1007', '1008', '1009'] as $f) {
            $this->actingAs($this->contador, 'sanctum')
                ->getJson($this->tenantUrl("/reports/exogena-{$f}?año=1999"))
                ->assertStatus(422);

            $this->actingAs($this->contador, 'sanctum')
                ->getJson($this->tenantUrl("/reports/exogena-{$f}"))
                ->assertStatus(422);
        }
    }
}
