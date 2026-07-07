<?php

declare(strict_types=1);

namespace Tests\Feature\Periodo;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use App\Services\Periodo\PeriodoOperacionInvalidaException;
use App\Services\Periodo\ReabrirPeriodoService;
use Illuminate\Support\Str;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use Tests\TenantTestCase;

/**
 * Tests Feature del ciclo de vida de Periodos contables.
 *
 * Cubre:
 *   - Cierre con borradores pendientes → 422
 *   - Cierre anual genera asientos de cancelación y traslado
 *   - Doble cierre rechazado
 *   - Reapertura con dual-approval (distintos usuarios)
 *   - Expiración de solicitud de reapertura (30 min)
 *   - Bloqueado fiscal no se puede reabrir
 */
class PeriodoFeatureTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function crearUsuario(string $role): User
    {
        return User::create([
            'nombre'   => 'Test',
            'apellido' => ucfirst($role),
            'email'    => $role . '-periodo-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => $role,
            'activo'   => true,
        ]);
    }

    // ── Test 1: Cierre con borradores ─────────────────────────────────────────

    public function test_close_periodo_with_borradores_returns_422(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 1]);
        $contador = $this->crearUsuario('contador');

        // Crear un asiento borrador en el periodo sin disparar eventos
        Asiento::withoutEvents(function () use ($periodo, $contador): void {
            $a = new Asiento();
            $a->forceFill([
                'tipo_comprobante' => 'DB',
                'comprobante'      => 'Diario',
                'numero_documento' => 'BORRADOR-FT-001',
                'fecha'            => $periodo->fecha_inicio,
                'periodo_id'       => $periodo->id,
                'año_fiscal'       => $periodo->año_fiscal,
                'glosa'            => 'Borrador pendiente bloquea el cierre',
                'estado'           => Asiento::ESTADO_BORRADOR,
                'created_by_id'    => $contador->id,
            ]);
            $a->save();
        });

        // Contador intenta cerrar el periodo → PreCierreFallidoException → 422
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/periodos/{$periodo->id}/cerrar"), [
                'confirmar' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ── Test 2: Cierre anual genera asientos ──────────────────────────────────

    public function test_close_periodo_anual_genera_asientos_de_cierre(): void
    {
        // Prerequisito: el servicio requiere un periodo anual en estado CERRADO.
        // El seeder PUC garantiza cuentas 590505 (5905%) y 360505 (3605%) con acepta_movimientos=true.
        $this->crearPeriodo([
            'tipo'        => PeriodoContable::TIPO_ANUAL,
            'codigo'      => 'FY-2026',
            'año_fiscal'  => 2026,
            'mes'         => 1,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin'   => '2026-12-31',
            'estado'      => PeriodoContable::ESTADO_CERRADO,
        ]);

        // El CierreAnualService genera 2 asientos: cancelación y traslado a patrimonio.
        // POST /cierre-anual/{año} delega al servicio que ya está probado a nivel de unit.
        // Aquí verificamos que el endpoint HTTP funciona de extremo a extremo.
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->tenantUrl('/cierre-anual/2026'), ['confirmar' => true])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertNotNull($data, 'El cierre anual debe retornar data con los asientos generados');
    }

    // ── Test 3: Doble cierre rechazado ────────────────────────────────────────

    public function test_close_periodo_dos_veces_no_duplica(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 2]);
        $contador = $this->crearUsuario('contador');

        // Primer cierre: exitoso → 200
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/periodos/{$periodo->id}/cerrar"), [
                'confirmar' => true,
            ])
            ->assertStatus(200);

        // Segundo cierre: periodo ya está CERRADO → policy::close falla (no abierto) → 403
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/periodos/{$periodo->id}/cerrar"), [
                'confirmar' => true,
            ])
            ->assertStatus(403);

        // Verificar que el periodo solo fue cerrado una vez
        $periodoRefrescado = $periodo->fresh();
        $this->assertEquals(PeriodoContable::ESTADO_CERRADO, $periodoRefrescado->estado);
    }

    // ── Test 4: Dual-approval con usuarios distintos ──────────────────────────

    public function test_reopen_requires_dual_approval_distinct_users(): void
    {
        $periodo  = $this->crearPeriodo([
            'año_fiscal' => 2026,
            'mes'        => 3,
            'estado'     => PeriodoContable::ESTADO_CERRADO,
        ]);
        $contador = $this->crearUsuario('contador');
        $admin    = $this->crearUsuario('admin');

        /** @var ReabrirPeriodoService $service */
        $service = $this->app->make(ReabrirPeriodoService::class);

        // Paso 1: contador solicita la reapertura
        $req = $service->solicitar(
            $periodo,
            $contador,
            'Motivo extenso de reapertura para test de dual-approval entre usuarios diferentes.'
        );

        // Paso 2a: el mismo contador intenta aprobar → excepción (mismo usuario)
        try {
            $service->aprobar($req->id, $contador);
            $this->fail('Debería haber lanzado PeriodoOperacionInvalidaException');
        } catch (PeriodoOperacionInvalidaException $e) {
            $this->assertStringContainsString('mismo usuario', $e->getMessage());
        }

        // Paso 2b: admin diferente aprueba → exitoso
        $periodoReabierto = $service->aprobar($req->id, $admin);
        $this->assertEquals(PeriodoContable::ESTADO_ABIERTO, $periodoReabierto->estado);
    }

    // ── Test 5: Solicitud expira en 30 minutos ────────────────────────────────

    public function test_reopen_request_expires_after_30_min(): void
    {
        $periodo  = $this->crearPeriodo([
            'año_fiscal' => 2026,
            'mes'        => 4,
            'estado'     => PeriodoContable::ESTADO_CERRADO,
        ]);
        $contador = $this->crearUsuario('contador');
        $admin    = $this->crearUsuario('admin');

        /** @var ReabrirPeriodoService $service */
        $service = $this->app->make(ReabrirPeriodoService::class);

        $req = $service->solicitar(
            $periodo,
            $contador,
            'Motivo de reapertura para test de expiración de solicitud pendiente.'
        );

        // Simular que pasaron 31 minutos (la solicitud expira en 30)
        $this->travel(31)->minutes();

        try {
            $service->aprobar($req->id, $admin);
            $this->fail('Debería haber lanzado PeriodoOperacionInvalidaException por expiración');
        } catch (PeriodoOperacionInvalidaException $e) {
            $this->assertStringContainsString('expir', $e->getMessage());
        } finally {
            // Restaurar el tiempo para no afectar otros tests
            $this->travelBack();
        }
    }

    // ── Test 6: Bloqueado fiscal no se reabre ─────────────────────────────────

    public function test_bloqueado_fiscal_no_se_reabre(): void
    {
        $periodo  = $this->crearPeriodo([
            'año_fiscal' => 2025,
            'mes'        => 12,
            'estado'     => PeriodoContable::ESTADO_BLOQUEADO_FISCAL,
        ]);
        $contador = $this->crearUsuario('contador');

        /** @var ReabrirPeriodoService $service */
        $service = $this->app->make(ReabrirPeriodoService::class);

        // El service verifica bloqueado_fiscal ANTES de crear el DualApproval
        $this->expectException(PeriodoOperacionInvalidaException::class);
        $this->expectExceptionMessageMatches('/bloqueado/i');

        $service->solicitar(
            $periodo,
            $contador,
            'Intento de reapertura de periodo bloqueado fiscalmente por auditoría.'
        );
    }
}
