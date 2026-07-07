<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * FEAT-N: Exportar exógena a CSV pipe-delimited (compatible MUISCA DIAN).
 *
 * Valida:
 *  - Content-Type correcto (text/csv)
 *  - Content-Disposition con nombre de archivo
 *  - BOM UTF-8 al inicio (Excel)
 *  - Delimitador pipe `|`
 *  - Encabezados presentes
 *  - Filas de datos correctas
 *  - Montos como enteros (sin decimales, formato DIAN)
 */
class ExogenaCsvExportTest extends TenantTestCase
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
            'apellido' => 'CSV',
            'email'    => 'csv-' . Str::random(6) . '@test.com',
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
            'dv'                          => '7',
            'razon_social'                => $razon,
            'municipio_id'                => '11001',
            'es_proveedor'                => true,
            'activo'                      => true,
        ]);
    }

    public function test_feat_n_csv_1001_devuelve_content_type_correcto(): void
    {
        $this->setupBase();
        $p = $this->crearProveedor('800600600', 'Proveedor CSV Test');

        DocumentoIngreso::create([
            'tercero_id'                 => $p->id,
            'numero'                     => 'ING-CSV-1',
            'tipo'                       => 'factura_compra',
            'fecha'                      => '2070-04-10',
            'concepto'                   => 'Test CSV',
            'forma_pago'                 => 'credito',
            'valor_bruto'                => 2_000_000,
            'valor_iva'                  => 380_000,
            'valor_retefuente'           => 50_000,
            'valor_reteica'              => 8_280,
            'valor_reteiva'              => 0,
            'valor_total'                => 2_321_720,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-CSV-1',
        ]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/exogena-1001/csv?año=2070'));

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $resp->assertHeader('Content-Disposition', 'attachment; filename="exogena-1001-2070.csv"');
    }

    public function test_feat_n_csv_1001_estructura_correcta(): void
    {
        $this->setupBase();
        $p = $this->crearProveedor('800700700', 'Proveedor Estructura');

        DocumentoIngreso::create([
            'tercero_id'                 => $p->id,
            'numero'                     => 'ING-CSV-2',
            'tipo'                       => 'factura_compra',
            'fecha'                      => '2071-04-10',
            'concepto'                   => 'Test',
            'forma_pago'                 => 'credito',
            'valor_bruto'                => 1_500_000,
            'valor_iva'                  => 285_000,
            'valor_retefuente'           => 37_500,
            'valor_reteica'              => 0,
            'valor_reteiva'              => 0,
            'valor_total'                => 1_747_500,
            'estado'                     => 'registrado',
            'numero_documento_proveedor' => 'FP-CSV-2',
        ]);

        $resp = $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/exogena-1001/csv?año=2071'));

        $body = $resp->getContent();

        // BOM UTF-8 al inicio
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV debe iniciar con BOM UTF-8');

        // Pipe delimiter en headers
        $this->assertStringContainsString('tipo_documento|identificacion|dv|razon_social', $body,
            'Headers separados por pipe');

        // Razón social del proveedor en alguna línea
        $this->assertStringContainsString('Proveedor Estructura', $body);

        // Montos como enteros (sin punto decimal)
        $this->assertStringContainsString('1500000', $body, 'Base sin decimales');
        $this->assertStringContainsString('37500', $body, 'Retefuente sin decimales');
        $this->assertStringNotContainsString('1500000.00', $body, 'No debe tener .00');
    }

    public function test_feat_n_csv_formato_invalido_retorna_404(): void
    {
        $this->setupBase();

        $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/exogena-9999/csv?año=2070'))
            ->assertStatus(404);
    }

    public function test_feat_n_csv_anio_invalido_retorna_422(): void
    {
        $this->setupBase();

        $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/exogena-1001/csv?año=1999'))
            ->assertStatus(422);

        $this->actingAs($this->contador, 'sanctum')
            ->get($this->tenantUrl('/reports/exogena-1001/csv'))
            ->assertStatus(422);
    }

    public function test_feat_n_csv_todos_los_formatos_descargan(): void
    {
        $this->setupBase();

        // Por cada formato debe responder 200 con CSV (aunque esté vacío)
        foreach ([1001, 1003, 1005, 1006, 1007, 1008, 1009] as $f) {
            $resp = $this->actingAs($this->contador, 'sanctum')
                ->get($this->tenantUrl("/reports/exogena-{$f}/csv?año=2072"));

            $resp->assertOk();
            $body = $resp->getContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body,
                "Formato {$f}: CSV debe iniciar con BOM");
            $this->assertStringContainsString('|', $body,
                "Formato {$f}: debe contener delimitador pipe");
        }
    }
}
