<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\Bodega;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\Tenant\Producto;
use App\Models\Tenant\Resolucion;
use App\Models\Tenant\Sucursal;
use App\Models\Tenant\Tercero;
use App\Models\Tenant\TipoComprobante;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Regresión FEAT-A: los endpoints /reports/withholdings y
 * /reports/withholding-certificate/{tercero} deben leer facturas con
 * retenciones SIN importar el estado DIAN.
 *
 * Antes del fix:
 *   - withholdings filtraba `estado = 'validado'` → sin Factus configurado,
 *     todas las facturas quedaban en 'borrador' o 'error' y el reporte
 *     retornaba vacío.
 *   - certificate filtraba además por `fecha_validacion` (NULL en borrador),
 *     duplicando el problema.
 *
 * Tras el fix:
 *   - Filtro por estado pasa a `!= 'anulado'` (todas las activas cuentan).
 *   - Filtro temporal usa `fecha_emision` (siempre existe), no fecha_validacion.
 */
class ReportControllerWithholdingsTest extends TenantTestCase
{
    private User $contador;
    private Tercero $cliente;
    private TipoComprobante $comprobante;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupFixtures(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Vendedor',
            'apellido' => 'Reportes',
            'email'    => 'vendedor-rpt-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Período del mes actual (idempotente)
        $anio = (int) date('Y');
        $mes  = (int) date('m');
        $codigo = sprintf('%04d-%02d', $anio, $mes);
        if (PeriodoContable::where('codigo', $codigo)->doesntExist()) {
            $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => $mes]);
        }

        // Cliente (gran contribuyente que NOS retiene)
        $this->cliente = Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => '890999111',
            'identificacion_documento_id' => '31',
            'identificacion'              => '890999111-' . rand(0, 9),
            'razon_social'                => 'Almacenes Gran Contribuyente S.A.',
            'email'                       => 'gc@test.co',
            'es_cliente'                  => true,
            'activo'                      => true,
        ]);

        // Resolución LOCAL (sin Factus) → factura cae en borrador
        $resolucion = Resolucion::create([
            'nombre'            => 'Resolución Test Local',
            'prefijo'           => 'LOC',
            'desde'             => 1,
            'hasta'             => 9999,
            'numero_resolucion' => 'LOCAL-TEST',
            'fecha_inicio'      => now()->toDateString(),
            'fecha_fin'         => now()->addYear()->toDateString(),
            'factus_id'         => null,
            'activa'            => true,
        ]);

        $this->comprobante = TipoComprobante::create([
            'codigo'             => 'FV-RPT' . Str::random(4),
            'nombre'             => 'Factura Venta Test',
            'tipo_documento'     => 'FV',
            'resolucion_id'      => $resolucion->id,
            'consecutivo_actual' => 0,
            'activo'             => true,
        ]);

        // Parametrización mínima para asiento de venta + anticipo retefuente
        foreach ([
            ['venta.cuenta_cxc',                  '130505'],
            ['venta.cuenta_ingresos',             '413505'],
            ['venta.cuenta_iva_generado',         '240805'],
            ['factura.cuenta_retefuente_ventas',  '135515'],
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

    private function crearFacturaConRetencion(): string
    {
        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), [
                'tipo_comprobante_id'  => $this->comprobante->id,
                'tercero_id'           => $this->cliente->id,
                'fecha_emision'        => now()->toDateString(),
                'payment_form'         => '1',
                'payment_method_code'  => '10',
                'items'                => [[
                    'codigo'   => 'SERV-RPT',
                    'nombre'   => 'Servicio facturado con retención',
                    'cantidad' => 1,
                    'precio'   => 1_000_000,
                    'tax_rate' => 19,
                ]],
                'withholding_taxes'    => [
                    ['code' => '05', 'rate' => 2.5],
                ],
            ]);

        $response->assertStatus(201);

        return $response->json('data.id');
    }

    public function test_feat_a_withholdings_incluye_facturas_borrador(): void
    {
        $this->setupFixtures();
        $facturaId = $this->crearFacturaConRetencion();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                '/reports/withholdings?start_date=' . now()->subMonth()->toDateString()
                . '&end_date=' . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        $items = collect($resp->json('data'));

        $this->assertGreaterThan(
            0,
            $items->count(),
            'FEAT-A: el reporte de retenciones debe incluir facturas en estado borrador. '
            . 'Antes del fix retornaba vacío al filtrar por estado=validado.',
        );

        $linea = $items->first();
        $this->assertSame('05', $linea['codigo'] ?? null);
        $this->assertEqualsWithDelta(25000.0, (float) ($linea['valor'] ?? 0), 0.01,
            'Retención = 2.5% × 1.000.000 = 25.000');

        // total agregado
        $this->assertEqualsWithDelta(
            25000.0,
            (float) $resp->json('total_retenido'),
            0.01,
            'total_retenido del reporte debe coincidir con la única retención.',
        );
    }

    public function test_feat_a_certificate_incluye_factura_borrador_por_tercero(): void
    {
        $this->setupFixtures();
        $this->crearFacturaConRetencion();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                "/reports/withholding-certificate/{$this->cliente->id}"
                . '?start_date=' . now()->subMonth()->toDateString()
                . '&end_date=' . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        $retenciones = collect($resp->json('retenciones'));
        $this->assertGreaterThan(
            0,
            $retenciones->count(),
            'FEAT-A: el certificado del tercero debe incluir facturas en estado borrador.',
        );

        $codigos = $retenciones->pluck('codigo')->toArray();
        $this->assertContains('05', $codigos);
    }

    public function test_feat_a_certificate_no_incluye_facturas_anuladas(): void
    {
        $this->setupFixtures();
        $facturaId = $this->crearFacturaConRetencion();

        // Anular la factura — bypass de guards/observers via forceFill
        $fac = \App\Models\Tenant\Factura::findOrFail($facturaId);
        $fac->forceFill(['estado' => 'anulado'])->save();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                "/reports/withholding-certificate/{$this->cliente->id}"
                . '?start_date=' . now()->subMonth()->toDateString()
                . '&end_date=' . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $retenciones = collect($resp->json('retenciones'));
        $this->assertEquals(
            0,
            $retenciones->count(),
            'FEAT-A: facturas anuladas NO deben contar en el certificado.',
        );
    }
}
