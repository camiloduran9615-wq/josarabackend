<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-D: Reporte Formulario 350 (retenciones practicadas mensual).
 *
 * Consolida lo que la empresa retuvo a sus proveedores en el mes para
 * declarar y consignar a la DIAN. Incluye detalle por proveedor.
 */
class ReportRetencionesMensualTest extends TenantTestCase
{
    private User $contador;

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function setupFixtures(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'F350',
            'email'    => 'f350-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);
    }

    private function crearProveedor(string $nit, string $razon): Tercero
    {
        return Tercero::create([
            'tipo_persona'                => 'juridica',
            'tipo_documento'              => 'nit',
            'numero_documento'            => $nit,
            'identificacion_documento_id' => '31',
            'identificacion'              => $nit . '-' . rand(0, 9),
            'razon_social'                => $razon,
            'es_proveedor'                => true,
            'activo'                      => true,
        ]);
    }

    private function crearCompra(Tercero $proveedor, string $fecha, float $base,
        float $retefuente = 0, float $reteica = 0, float $reteiva = 0): void
    {
        DocumentoIngreso::create([
            'tercero_id'                 => $proveedor->id,
            'numero'                     => 'ING-' . Str::random(6),
            'tipo'                       => 'factura_compra',
            'fecha'                      => $fecha,
            'concepto'                   => 'Test F350',
            'forma_pago'                 => 'credito',
            'valor_bruto'                => $base,
            'valor_iva'                  => $base * 0.19,
            'valor_retefuente'           => $retefuente,
            'valor_reteica'              => $reteica,
            'valor_reteiva'              => $reteiva,
            'valor_total'                => $base + ($base * 0.19) - $retefuente - $reteica - $reteiva,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-' . Str::random(6),
        ]);
    }

    public function test_feat_d_consolida_retenciones_del_mes(): void
    {
        $this->setupFixtures();
        $p1 = $this->crearProveedor('800400100', 'Proveedor A');
        $p2 = $this->crearProveedor('800400200', 'Proveedor B');

        // Mes objetivo: julio 2031
        $this->crearCompra($p1, '2031-07-05', base: 2_000_000, retefuente: 50_000, reteica: 8_280);
        $this->crearCompra($p1, '2031-07-15', base: 1_000_000, retefuente: 25_000);
        $this->crearCompra($p2, '2031-07-20', base: 3_000_000, retefuente: 75_000, reteiva: 95_000);

        // Compra FUERA del mes (agosto) — NO debe contar
        $this->crearCompra($p1, '2031-08-05', base: 5_000_000, retefuente: 125_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/retenciones-mensual?año=2031&mes=7'));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('periodo.mes', 7);
        $resp->assertJsonPath('periodo.desde', '2031-07-01');
        $resp->assertJsonPath('periodo.hasta', '2031-07-31');

        // Totales: 50K + 25K + 75K = 150K retefuente
        $this->assertEqualsWithDelta(150_000.0,
            (float) $resp->json('totales.retefuente'),
            0.01);
        $this->assertEqualsWithDelta(8_280.0,
            (float) $resp->json('totales.reteica'),
            0.01);
        $this->assertEqualsWithDelta(95_000.0,
            (float) $resp->json('totales.reteiva'),
            0.01);
        $this->assertEqualsWithDelta(253_280.0,
            (float) $resp->json('totales.total_a_consignar'),
            0.01);

        // 3 documentos en el mes (la 4ta es de agosto)
        $this->assertEquals(3, $resp->json('totales.num_documentos'));
    }

    public function test_feat_d_detalle_por_proveedor_ordenado_por_monto(): void
    {
        $this->setupFixtures();
        $pequeno = $this->crearProveedor('800500100', 'Proveedor Pequeño');
        $grande  = $this->crearProveedor('800500200', 'Proveedor Grande');

        $this->crearCompra($pequeno, '2031-09-10', base: 1_000_000, retefuente: 25_000);
        $this->crearCompra($grande,  '2031-09-15', base: 5_000_000, retefuente: 125_000, reteica: 20_700);
        $this->crearCompra($grande,  '2031-09-20', base: 3_000_000, retefuente: 75_000);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/retenciones-mensual?año=2031&mes=9'));

        $resp->assertOk();
        $detalle = collect($resp->json('detalle_proveedor'));

        $this->assertEquals(2, $detalle->count(), 'Dos proveedores con retenciones en sept');

        // Primer item debe ser el "grande" (mayor total)
        $primero = $detalle->first();
        $this->assertSame($grande->id, $primero['tercero_id']);
        $this->assertEqualsWithDelta(200_000.0, (float) $primero['retefuente'], 0.01,
            'Grande: 125K + 75K = 200K retefuente');
        $this->assertEqualsWithDelta(20_700.0, (float) $primero['reteica'], 0.01);
        $this->assertEquals(2, $primero['num_documentos']);
    }

    public function test_feat_d_excluye_documentos_anulados(): void
    {
        $this->setupFixtures();
        $p = $this->crearProveedor('800600100', 'Test Anulación');

        $this->crearCompra($p, '2031-10-10', base: 2_000_000, retefuente: 50_000);

        // Anular el documento
        DocumentoIngreso::where('tercero_id', $p->id)
            ->get()
            ->first()
            ->forceFill(['estado' => 'anulado'])
            ->save();

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/retenciones-mensual?año=2031&mes=10'));

        $resp->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $resp->json('totales.retefuente'), 0.01);
        $this->assertEquals(0, $resp->json('totales.num_documentos'));
    }

    public function test_feat_d_mes_invalido_retorna_422(): void
    {
        $this->setupFixtures();

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/retenciones-mensual?año=2031&mes=13'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->getJson($this->tenantUrl('/reports/retenciones-mensual?mes=5'))
            ->assertStatus(422);
    }
}
