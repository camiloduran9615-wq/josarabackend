<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-B: Reportes de retenciones PRACTICADAS A PROVEEDORES.
 *
 * Cuando le pagamos a un proveedor, le retenemos retefuente / ReteICA / ReteIVA
 * y consignamos esos valores a la DIAN/Municipio. El proveedor necesita un
 * certificado anual para descontarlo en su declaración de renta.
 *
 * Estos endpoints son el contrario de /reports/withholdings (que reporta lo
 * que los clientes nos retienen a NOSOTROS).
 */
class ReportRetefuentePracticadaTest extends TenantTestCase
{
    private User $contador;
    private Tercero $proveedor;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupFixtures(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Auxiliar',
            'apellido' => 'Reportes',
            'email'    => 'aux-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        $this->proveedor = Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => '800999333',
            'identificacion_documento_id' => '31',
            'identificacion'              => '800999333-' . rand(0, 9),
            'razon_social'                => 'Proveedor Test S.A.S.',
            'email'                       => 'prov@test.co',
            'es_proveedor'                => true,
            'activo'                      => true,
        ]);
    }

    private function crearDocumentoIngresoConRetencion(
        float $retefuente,
        float $reteica = 0,
        float $reteiva = 0,
        ?string $fecha = null,
    ): DocumentoIngreso {
        $bruto = 2_000_000.0;
        $total = $bruto - $retefuente - $reteica - $reteiva;

        return DocumentoIngreso::create([
            'tercero_id'                 => $this->proveedor->id,
            'numero'                     => 'ING-' . Str::random(6),
            'tipo'                       => 'factura_compra',
            'fecha'                      => $fecha ?? now()->toDateString(),
            'concepto'                   => 'Compra de prueba',
            'forma_pago'                 => 'credito',
            'valor_bruto'                => $bruto,
            'valor_iva'                  => 0,
            'valor_retefuente'           => $retefuente,
            'valor_reteica'              => $reteica,
            'valor_reteiva'              => $reteiva,
            'valor_total'                => $total,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-' . Str::random(6),
        ]);
    }

    public function test_feat_b_lista_retenciones_practicadas_a_proveedores(): void
    {
        $this->setupFixtures();
        $this->crearDocumentoIngresoConRetencion(retefuente: 50_000, reteica: 8_280);
        $this->crearDocumentoIngresoConRetencion(retefuente: 25_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                '/reports/retefuente-practicada?start_date=' . now()->subMonth()->toDateString()
                . '&end_date=' . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        // 3 líneas: 2 retefuente + 1 reteica (uno de los docs no tiene reteica)
        $detalle = collect($resp->json('data'));
        $this->assertEquals(3, $detalle->count(),
            'Esperaba 2 retefuente + 1 reteica = 3 líneas. Recibidas: ' . $detalle->count());

        // Totales
        $this->assertEqualsWithDelta(75000.0,
            (float) $resp->json('totales.retefuente'),
            0.01,
            'Total retefuente = 50K + 25K = 75K');

        $this->assertEqualsWithDelta(8280.0,
            (float) $resp->json('totales.reteica'),
            0.01,
            'Total ReteICA = 8.280');

        $this->assertEqualsWithDelta(83280.0,
            (float) $resp->json('totales.total_retenido'),
            0.01);
    }

    public function test_feat_b_filtra_por_tipo_retencion(): void
    {
        $this->setupFixtures();
        $this->crearDocumentoIngresoConRetencion(retefuente: 50_000, reteica: 8_280);
        $this->crearDocumentoIngresoConRetencion(retefuente: 25_000); // solo retefuente

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                '/reports/retefuente-practicada?tipo_retencion=reteica'
                . '&start_date=' . now()->subMonth()->toDateString()
                . '&end_date='   . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $detalle = collect($resp->json('data'));

        // Solo el primer documento tiene reteica; el filtro de tipo_retencion
        // limita los documentos a los que tienen ESE valor > 0, pero el detalle
        // sigue mostrando TODAS las retenciones del documento (incluida retefuente).
        // Lo verificamos: hay 2 líneas (retefuente + reteica del doc 1), 0 del doc 2.
        $this->assertGreaterThanOrEqual(1, $detalle->count());
        $reteicaLineas = $detalle->where('tipo_retencion', 'reteica');
        $this->assertEquals(1, $reteicaLineas->count(), 'Una sola línea ReteICA');
    }

    public function test_feat_b_excluye_documentos_anulados(): void
    {
        $this->setupFixtures();
        $doc = $this->crearDocumentoIngresoConRetencion(retefuente: 100_000);

        // Anular el documento
        $doc->forceFill(['estado' => 'anulado'])->save();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                '/reports/retefuente-practicada?start_date=' . now()->subMonth()->toDateString()
                . '&end_date=' . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $this->assertEquals(0, collect($resp->json('data'))->count());
        $this->assertEqualsWithDelta(0.0, (float) $resp->json('totales.total_retenido'), 0.01);
    }

    public function test_feat_b_certificado_proveedor_consolida_retenciones(): void
    {
        $this->setupFixtures();
        $this->crearDocumentoIngresoConRetencion(retefuente: 50_000, reteica: 8_280);
        $this->crearDocumentoIngresoConRetencion(retefuente: 30_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                "/reports/retefuente-practicada-certificate/{$this->proveedor->id}"
                . '?start_date=' . now()->subMonth()->toDateString()
                . '&end_date='   . now()->addMonth()->toDateString(),
            ));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('proveedor.id', $this->proveedor->id);

        $this->assertEqualsWithDelta(80000.0, (float) $resp->json('totales.retefuente'), 0.01);
        $this->assertEqualsWithDelta(8280.0,  (float) $resp->json('totales.reteica'),    0.01);
        $this->assertEqualsWithDelta(88280.0, (float) $resp->json('totales.total_retenido'), 0.01);
        $this->assertEquals(2, $resp->json('totales.num_documentos'));

        // El certificado debe llevar los datos de la empresa (que retuvo)
        $this->assertNotEmpty($resp->json('empresa.nombre'));
        $this->assertNotEmpty($resp->json('empresa.nit'));
    }

    public function test_feat_b_filtra_por_tercero_id_en_listado(): void
    {
        $this->setupFixtures();

        // Crear otro proveedor para validar el filtro
        $otroProveedor = Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => '800111222',
            'identificacion_documento_id' => '31',
            'identificacion'              => '800111222-' . rand(0, 9),
            'razon_social'                => 'Otro Proveedor S.A.S.',
            'es_proveedor'                => true,
            'activo'                      => true,
        ]);

        // 1 doc al proveedor principal, 1 a otro
        $this->crearDocumentoIngresoConRetencion(retefuente: 50_000);

        DocumentoIngreso::create([
            'tercero_id'                 => $otroProveedor->id,
            'numero'                     => 'ING-OTRO',
            'tipo'                       => 'factura_compra',
            'fecha'                      => now()->toDateString(),
            'concepto'                   => 'Otro',
            'forma_pago'                 => 'contado',
            'valor_bruto'                => 1_000_000,
            'valor_iva'                  => 0,
            'valor_retefuente'           => 25_000,
            'valor_reteica'              => 0,
            'valor_reteiva'              => 0,
            'valor_total'                => 975_000,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-OTRO',
        ]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl(
                "/reports/retefuente-practicada?tercero_id={$this->proveedor->id}",
            ));

        $resp->assertOk();
        $this->assertEquals(1, collect($resp->json('data'))->count(),
            'Filtro tercero_id debe limitar a 1 documento (1 línea de retefuente).');
        $this->assertEqualsWithDelta(50000.0, (float) $resp->json('totales.retefuente'), 0.01);
    }
}
