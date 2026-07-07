<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\Factura;
use App\Models\Tenant\ParametrizacionContable;
use App\Models\Tenant\Resolucion;
use App\Models\Tenant\Tercero;
use App\Models\Tenant\TipoComprobante;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-C: Reporte Formulario 300 IVA bimestral (DIAN).
 *
 * Valida que el endpoint /reports/iva-bimestral consolide correctamente:
 *  - Ingresos gravados por tarifa (19%, 5%, 0%)
 *  - IVA generado por tarifa
 *  - IVA descontable (de compras)
 *  - Saldo a pagar/favor
 */
class ReportIvaBimestralTest extends TenantTestCase
{
    private User $contador;
    private Tercero $cliente;
    private Tercero $proveedor;
    private TipoComprobante $comprobante;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupFixtures(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'Iva',
            'email'    => 'iva-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        $this->cliente = Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => '890800100',
            'identificacion_documento_id' => '31',
            'identificacion'              => '890800100-' . rand(0, 9),
            'razon_social'                => 'Cliente IVA Test',
            'es_cliente'                  => true,
            'activo'                      => true,
        ]);

        $this->proveedor = Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => '800200100',
            'identificacion_documento_id' => '31',
            'identificacion'              => '800200100-' . rand(0, 9),
            'razon_social'                => 'Proveedor IVA Test',
            'es_proveedor'                => true,
            'activo'                      => true,
        ]);

        $resolucion = Resolucion::create([
            'nombre'            => 'Res Local',
            'prefijo'           => 'LOC',
            'desde'             => 1,
            'hasta'             => 9999,
            'numero_resolucion' => 'LOCAL',
            'fecha_inicio'      => now()->subYear()->toDateString(),
            'fecha_fin'         => now()->addYear()->toDateString(),
            'factus_id'         => null,
            'activa'            => true,
        ]);

        $this->comprobante = TipoComprobante::create([
            'codigo'             => 'FV-IVA' . Str::random(4),
            'nombre'             => 'Factura Test IVA',
            'tipo_documento'     => 'FV',
            'resolucion_id'      => $resolucion->id,
            'consecutivo_actual' => 0,
            'activo'             => true,
        ]);

        // Parametrización mínima para que /facturas funcione
        foreach ([
            ['venta.cuenta_cxc',          '130505'],
            ['venta.cuenta_ingresos',     '413505'],
            ['venta.cuenta_iva_generado', '240805'],
        ] as [$clave, $codigo]) {
            $cuenta = CuentaContable::firstOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'              => 'Auto-' . $codigo,
                    'naturaleza'          => str_starts_with($codigo, '1') ? 'debito' : 'credito',
                    'nivel'               => 'subcuenta',
                    'clase'               => (int) substr($codigo, 0, 1),
                    'acepta_movimientos'  => true,
                    'exige_tercero'       => false,
                    'exige_centro_costo'  => false,
                    'exige_base_impuesto' => false,
                    'clasificacion_balance' => 'corriente',
                    'clasificacion_pyg'   => 'na',
                    'sistema'             => true,
                    'editable'            => false,
                    'activo'              => true,
                ],
            );
            ParametrizacionContable::updateOrCreate(
                ['clave' => $clave],
                ['cuenta_contable_id' => $cuenta->id, 'activo' => true],
            );
        }
    }

    private function crearVenta(int $base, int $tarifaIva, string $fecha): void
    {
        $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), [
                'tipo_comprobante_id' => $this->comprobante->id,
                'tercero_id'          => $this->cliente->id,
                'fecha_emision'       => $fecha,
                'payment_form'        => '1',
                'payment_method_code' => '10',
                'items'               => [[
                    'codigo'   => 'SERV-IVA',
                    'nombre'   => "Servicio IVA {$tarifaIva}%",
                    'cantidad' => 1,
                    'precio'   => $base,
                    'tax_rate' => $tarifaIva,
                ]],
            ])->assertStatus(201);
    }

    private function crearCompra(int $base, int $iva, string $fecha): void
    {
        DocumentoIngreso::create([
            'tercero_id'                 => $this->proveedor->id,
            'numero'                     => 'ING-' . Str::random(5),
            'tipo'                       => 'factura_compra',
            'fecha'                      => $fecha,
            'concepto'                   => 'Compra IVA',
            'forma_pago'                 => 'credito',
            'valor_bruto'                => $base,
            'valor_iva'                  => $iva,
            'valor_retefuente'           => 0,
            'valor_reteica'              => 0,
            'valor_reteiva'              => 0,
            'valor_total'                => $base + $iva,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-' . Str::random(5),
        ]);
    }

    public function test_feat_c_iva_bimestral_separa_tarifas(): void
    {
        $this->setupFixtures();

        // Ventas dentro del bimestre — mayo-junio 2030 (fuera de cualquier dato real)
        $this->crearVenta(base: 1_000_000, tarifaIva: 19, fecha: '2030-05-15'); // IVA 190K
        $this->crearVenta(base:   500_000, tarifaIva:  5, fecha: '2030-06-10'); // IVA 25K
        $this->crearVenta(base:   200_000, tarifaIva:  0, fecha: '2030-05-20'); // IVA 0 (exento)

        // Compra dentro del bimestre
        $this->crearCompra(base: 500_000, iva: 95_000, fecha: '2030-06-15');

        // Venta FUERA del bimestre (julio) — NO debe contar
        $this->crearVenta(base: 800_000, tarifaIva: 19, fecha: '2030-07-05');

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/iva-bimestral?año=2030&bimestre=3'));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('periodo.bimestre', 3);
        $resp->assertJsonPath('periodo.desde', '2030-05-01');
        $resp->assertJsonPath('periodo.hasta', '2030-06-30');

        // Ingresos por tarifa
        $this->assertEqualsWithDelta(1_000_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_19.base'),
            0.01);
        $this->assertEqualsWithDelta(190_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_19.iva'),
            0.01);

        $this->assertEqualsWithDelta(500_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_5.base'),
            0.01);
        $this->assertEqualsWithDelta(25_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_5.iva'),
            0.01);

        $this->assertEqualsWithDelta(200_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_0.base'),
            0.01);
        $this->assertEqualsWithDelta(0.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_0.iva'),
            0.01);

        // Totales
        $this->assertEqualsWithDelta(1_700_000.0,
            (float) $resp->json('ingresos.base_total'),
            0.01,
            'Base total = 1M (19%) + 500K (5%) + 200K (0%) = 1.700.000');

        $this->assertEqualsWithDelta(215_000.0,
            (float) $resp->json('ingresos.iva_generado'),
            0.01,
            'IVA generado = 190K + 25K + 0 = 215.000');

        // IVA descontable
        $this->assertEqualsWithDelta(95_000.0,
            (float) $resp->json('compras.iva_descontable'),
            0.01);

        // Saldo: generado 215K - descontable 95K = 120K a pagar
        $this->assertEqualsWithDelta(120_000.0,
            (float) $resp->json('balance.saldo_a_pagar'),
            0.01);
        $this->assertEqualsWithDelta(0.0,
            (float) $resp->json('balance.saldo_a_favor'),
            0.01);
    }

    public function test_feat_c_saldo_a_favor_cuando_iva_descontable_supera_generado(): void
    {
        $this->setupFixtures();

        // Ventas pequeñas
        $this->crearVenta(base: 100_000, tarifaIva: 19, fecha: '2030-09-15'); // IVA 19K

        // Compras grandes (más IVA pagado que generado)
        $this->crearCompra(base: 500_000, iva: 95_000, fecha: '2030-09-20');

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/iva-bimestral?año=2030&bimestre=5'));

        $resp->assertOk();

        // Generado 19K - Descontable 95K = -76K = a favor 76K
        $this->assertEqualsWithDelta(76_000.0,
            (float) $resp->json('balance.saldo_a_favor'),
            0.01,
            'Cuando IVA descontable > generado, hay saldo a favor');
        $this->assertEqualsWithDelta(0.0,
            (float) $resp->json('balance.saldo_a_pagar'),
            0.01);
    }

    public function test_feat_c_excluye_facturas_anuladas(): void
    {
        $this->setupFixtures();

        $this->crearVenta(base: 1_000_000, tarifaIva: 19, fecha: '2030-11-10');
        $this->crearVenta(base:   500_000, tarifaIva: 19, fecha: '2030-11-15');

        // Anular la primera del tercero recién creado (limita scope al test)
        $facturas = Factura::where('tercero_id', $this->cliente->id)
            ->orderBy('created_at')
            ->get();
        $facturas->first()->forceFill(['estado' => 'anulado'])->save();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/iva-bimestral?año=2030&bimestre=6'));

        $resp->assertOk();
        // Solo cuenta la segunda factura (500K base, 95K IVA)
        $this->assertEqualsWithDelta(500_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_19.base'),
            0.01);
        $this->assertEqualsWithDelta(95_000.0,
            (float) $resp->json('ingresos.por_tarifa.tarifa_19.iva'),
            0.01);
    }

    public function test_feat_c_bimestre_invalido_retorna_422(): void
    {
        $this->setupFixtures();

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/iva-bimestral?año=2030&bimestre=7'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/iva-bimestral?año=2030&bimestre=0'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/iva-bimestral?bimestre=3'))
            ->assertStatus(422);
    }
}
