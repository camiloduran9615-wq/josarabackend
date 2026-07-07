<?php

declare(strict_types=1);

namespace Tests\Feature\Conciliacion;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Tests de Conciliación Bancaria.
 *
 * Cubre: importar CSV, listar extractos y líneas, conciliar automático y manual.
 */
class ConciliacionBancariaTest extends TenantTestCase
{
    private function url(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    /** Crea un tercero mínimo y retorna su ID. */
    private function crearTerceroId(): string
    {
        $id = (string) Str::uuid();
        DB::table('terceros')->insert([
            'id'                          => $id,
            'identificacion_documento_id' => 'CC',
            'identificacion'              => (string) rand(10000000, 99999999),
            'razon_social'                => 'Tercero Test Conciliacion',
            'tributo_id'                  => 'ZZ',
            'es_cliente'                  => true,
            'es_proveedor'                => false,
            'es_empleado'                 => false,
            'activo'                      => true,
            'tipo_persona'                => 'Persona Natural',
            'sucursal'                    => '0',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
        return $id;
    }

    private function csvFake(int $lineas = 3): UploadedFile
    {
        $header  = "fecha,descripcion,referencia,debito,credito,saldo\n";
        $filas   = '';
        $saldo   = 1_000_000;

        for ($i = 1; $i <= $lineas; $i++) {
            $credito  = ($i % 2 === 0) ? 500_000 : 0;
            $debito   = ($i % 2 !== 0) ? 200_000 : 0;
            $saldo   += $credito - $debito;
            $fecha    = date('Y-m-d', strtotime("2026-01-0{$i}"));
            $filas   .= "{$fecha},Transacción {$i},REF{$i},{$debito},{$credito},{$saldo}\n";
        }

        $contenido = $header . $filas;
        $tmpPath   = tempnam(sys_get_temp_dir(), 'extracto_') . '.csv';
        file_put_contents($tmpPath, $contenido);

        return new UploadedFile($tmpPath, 'extracto.csv', 'text/csv', null, true);
    }

    private function importarExtracto(int $lineas = 4): array
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/extractos-bancarios/importar'), [
                'archivo'        => $this->csvFake($lineas),
                'banco'          => 'Bancolombia',
                'numero_cuenta'  => '123-456-78',
                'periodo_inicio' => '2026-01-01',
                'periodo_fin'    => '2026-01-31',
                'saldo_inicial'  => 1000000,
            ]);

        $response->assertCreated();
        return $response->json('data');
    }

    // ── Importar ──────────────────────────────────────────────────────────────

    public function test_importar_csv_crea_extracto_y_lineas(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/extractos-bancarios/importar'), [
                'archivo'        => $this->csvFake(5),
                'banco'          => 'Davivienda',
                'numero_cuenta'  => '987-654-32',
                'periodo_inicio' => '2026-02-01',
                'periodo_fin'    => '2026-02-28',
                'saldo_inicial'  => 500000,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.lineas', 5);

        $extractoId = $response->json('data.extracto_id');
        $this->assertDatabaseHas('extractos_bancarios', [
            'id'    => $extractoId,
            'banco' => 'Davivienda',
        ]);
        $this->assertDatabaseCount('lineas_extracto', 5);
    }

    public function test_importar_requiere_campos_obligatorios(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/extractos-bancarios/importar'), [
                'banco' => 'Solo Banco',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['archivo', 'numero_cuenta', 'periodo_inicio', 'periodo_fin']);
    }

    // ── Listar extractos ──────────────────────────────────────────────────────

    public function test_listar_extractos(): void
    {
        $this->importarExtracto();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url('/extractos-bancarios'));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data'));
    }

    // ── Líneas ────────────────────────────────────────────────────────────────

    public function test_obtener_lineas_del_extracto(): void
    {
        $data = $this->importarExtracto(4);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url("/extractos-bancarios/{$data['extracto_id']}/lineas"));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data',
                'stats' => ['total', 'conciliadas', 'pendientes', 'total_debito', 'total_credito'],
            ]);

        $this->assertEquals(4, $response->json('stats.total'));
        $this->assertEquals(0, $response->json('stats.conciliadas'));
        $this->assertEquals(4, $response->json('stats.pendientes'));
    }

    public function test_lineas_extracto_no_encontrado_retorna_404(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url('/extractos-bancarios/' . Str::uuid() . '/lineas'));

        $response->assertNotFound();
    }

    // ── Conciliar automático ──────────────────────────────────────────────────

    public function test_conciliar_auto_sin_matches_retorna_cero(): void
    {
        $data = $this->importarExtracto();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/extractos-bancarios/{$data['extracto_id']}/conciliar-auto"));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conciliadas', 0);
    }

    public function test_conciliar_auto_cruza_recibo_de_caja(): void
    {
        // Importar con 4 líneas; línea #2 (i=2) tiene credito=500.000, fecha=2026-01-02
        $data      = $this->importarExtracto(4);
        $terceroId = $this->crearTerceroId();

        DB::table('recibos_caja')->insert([
            'id'             => (string) Str::uuid(),
            'tercero_id'     => $terceroId,
            'numero'         => 'RC-AUTO-001',
            'fecha'          => '2026-01-02', // misma fecha que línea par crédito
            'valor_recibido' => 500_000,
            'concepto'       => 'Pago cliente test',
            'estado'         => 'borrador',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/extractos-bancarios/{$data['extracto_id']}/conciliar-auto"));

        $response->assertOk()
            ->assertJsonPath('data.conciliadas', 1);

        $this->assertDatabaseHas('conciliaciones', [
            'origen_type' => 'ReciboCaja',
        ]);
    }

    // ── Conciliar manual ──────────────────────────────────────────────────────

    public function test_conciliar_manual(): void
    {
        $data      = $this->importarExtracto();
        $terceroId = $this->crearTerceroId();

        // Primera línea pendiente
        $linea = DB::table('lineas_extracto')
            ->where('extracto_id', $data['extracto_id'])
            ->where('estado_conciliacion', 'pendiente')
            ->first();

        $this->assertNotNull($linea);

        $reciboId = (string) Str::uuid();
        DB::table('recibos_caja')->insert([
            'id'             => $reciboId,
            'tercero_id'     => $terceroId,
            'numero'         => 'RC-MANUAL-001',
            'fecha'          => $linea->fecha,
            'valor_recibido' => 300_000,
            'concepto'       => 'Pago manual test',
            'estado'         => 'borrador',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/extractos-bancarios/{$data['extracto_id']}/conciliar-manual"), [
                'linea_id'    => $linea->id,
                'origen_type' => 'ReciboCaja',
                'origen_id'   => $reciboId,
                'nota'        => 'Conciliación test manual',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('lineas_extracto', [
            'id'                  => $linea->id,
            'estado_conciliacion' => 'conciliado',
        ]);

        $this->assertDatabaseHas('conciliaciones', [
            'linea_extracto_id' => $linea->id,
            'origen_type'       => 'ReciboCaja',
            'origen_id'         => $reciboId,
            'tipo_conciliacion' => 'manual',
        ]);
    }

    public function test_conciliar_manual_ya_conciliada_retorna_409(): void
    {
        $data      = $this->importarExtracto();
        $terceroId = $this->crearTerceroId();

        $linea = DB::table('lineas_extracto')
            ->where('extracto_id', $data['extracto_id'])
            ->where('estado_conciliacion', 'pendiente')
            ->first();

        $reciboId = (string) Str::uuid();
        DB::table('recibos_caja')->insert([
            'id'             => $reciboId,
            'tercero_id'     => $terceroId,
            'numero'         => 'RC-DUP-001',
            'fecha'          => $linea->fecha,
            'valor_recibido' => 100_000,
            'concepto'       => 'Pago duplicado test',
            'estado'         => 'borrador',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $payload = [
            'linea_id'    => $linea->id,
            'origen_type' => 'ReciboCaja',
            'origen_id'   => $reciboId,
        ];

        $url = $this->url("/extractos-bancarios/{$data['extracto_id']}/conciliar-manual");

        // Primera vez — OK
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($url, $payload)->assertOk();

        // Segunda vez — conflicto
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($url, $payload)->assertStatus(409);
    }

    public function test_conciliar_manual_requiere_campos(): void
    {
        $data = $this->importarExtracto();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/extractos-bancarios/{$data['extracto_id']}/conciliar-manual"), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['linea_id', 'origen_type', 'origen_id']);
    }
}
