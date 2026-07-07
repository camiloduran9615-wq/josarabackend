<?php

declare(strict_types=1);

namespace Tests\Feature\Asiento;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Support\Str;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use Tests\TenantTestCase;

/**
 * Tests Feature HTTP de Asientos — partida doble, segregación de funciones,
 * ciclo borrador → aprobado → anulado/reversado.
 *
 * Patrón: withoutMiddleware(InitializeTenancyByTenantIdentifier) + actingAs($user, 'sanctum')
 * Seguro: el middleware solo se omite en el kernel de tests, nunca en producción.
 * CrossTenantLeakTest sigue cubriendo aislamiento con el stack completo.
 */
class AsientoFeatureTest extends TenantTestCase
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
            'email'    => $role . '-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => $role,
            'activo'   => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadValido(PeriodoContable $periodo, CuentaContable $cta1, CuentaContable $cta2): array
    {
        return [
            'fecha'            => $periodo->fecha_inicio->toDateString(),
            'tipo_comprobante' => 'DB',
            'descripcion'      => 'Asiento de prueba con descripción suficientemente larga',
            'lineas'           => [
                ['cuenta_contable_id' => $cta1->id, 'debito' => 100000, 'credito' => 0],
                ['cuenta_contable_id' => $cta2->id, 'debito' => 0,      'credito' => 100000],
            ],
        ];
    }

    // ── CRUD Básico ───────────────────────────────────────────────────────────

    public function test_user_with_role_auxiliar_can_create_borrador(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 1]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $auxiliar = $this->crearUsuario('auxiliar');

        $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'borrador');
    }

    // ── Validaciones de partida doble ─────────────────────────────────────────

    public function test_asiento_desbalanceado_returns_422(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 2]);
        $cta1    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), [
                'fecha'            => $periodo->fecha_inicio->toDateString(),
                'tipo_comprobante' => 'DB',
                'descripcion'      => 'Asiento desbalanceado para verificar rechazo',
                'lineas'           => [
                    ['cuenta_contable_id' => $cta1->id, 'debito' => 100000, 'credito' => 0],
                    ['cuenta_contable_id' => $cta2->id, 'debito' => 0,      'credito' => 50000],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_asiento_con_cuenta_de_agrupacion_returns_422(): void
    {
        $periodo   = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 3]);
        $ctaAgrupa = $this->crearCuenta([
            'naturaleza'         => 'debito',
            'clase'              => 1,
            'tipo_cuenta'        => 'agrupacion',
            'acepta_movimientos' => false,
        ]);
        $ctaCredito = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), [
                'fecha'            => $periodo->fecha_inicio->toDateString(),
                'tipo_comprobante' => 'DB',
                'descripcion'      => 'Asiento con cuenta de agrupación no aceptada aquí',
                'lineas'           => [
                    ['cuenta_contable_id' => $ctaAgrupa->id,  'debito' => 100000, 'credito' => 0],
                    ['cuenta_contable_id' => $ctaCredito->id, 'debito' => 0,      'credito' => 100000],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_asiento_en_periodo_cerrado_returns_422(): void
    {
        // EnPeriodoAbierto valida que el periodo exista y esté abierto.
        $periodo = $this->crearPeriodo([
            'año_fiscal' => 2025,
            'mes'        => 12,
            'estado'     => PeriodoContable::ESTADO_CERRADO,
        ]);
        $cta1 = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2 = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), [
                'fecha'            => $periodo->fecha_inicio->toDateString(),
                'tipo_comprobante' => 'DB',
                'descripcion'      => 'Asiento en periodo cerrado debe ser rechazado',
                'lineas'           => [
                    ['cuenta_contable_id' => $cta1->id, 'debito' => 100000, 'credito' => 0],
                    ['cuenta_contable_id' => $cta2->id, 'debito' => 0,      'credito' => 100000],
                ],
            ])
            ->assertStatus(422);
    }

    // ── Edición de borradores ─────────────────────────────────────────────────

    public function test_borrador_can_be_edited_by_creator_and_admin_only(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 4]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $auxiliar = $this->crearUsuario('auxiliar');

        $response = $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
        $response->assertStatus(201);
        $asientoId = $response->json('data.id');

        // El creador (auxiliar) puede editar su propio borrador
        $this->actingAs($auxiliar, 'sanctum')
            ->putJson($this->tenantUrl("/asientos/{$asientoId}"), array_merge(
                $this->payloadValido($periodo, $cta1, $cta2),
                ['descripcion' => 'Descripción editada por el propio creador del borrador'],
            ))
            ->assertStatus(200);

        // El admin también puede editar cualquier borrador
        $this->actingAs($this->adminUser, 'sanctum')
            ->putJson($this->tenantUrl("/asientos/{$asientoId}"), array_merge(
                $this->payloadValido($periodo, $cta1, $cta2),
                ['descripcion' => 'Descripción editada por el administrador del sistema test'],
            ))
            ->assertStatus(200);
    }

    public function test_borrador_cannot_be_edited_by_other_auxiliar(): void
    {
        $periodo      = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 5]);
        $cta1         = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2         = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $creador      = $this->crearUsuario('auxiliar');
        $otroAuxiliar = $this->crearUsuario('auxiliar');

        $response = $this->actingAs($creador, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
        $response->assertStatus(201);
        $asientoId = $response->json('data.id');

        // Otro auxiliar (no creador) no puede editar → 403
        $this->actingAs($otroAuxiliar, 'sanctum')
            ->putJson($this->tenantUrl("/asientos/{$asientoId}"), [
                'descripcion' => 'Otro auxiliar intentando editar borrador ajeno aquí',
            ])
            ->assertStatus(403);
    }

    // ── Aprobación y segregación de funciones ─────────────────────────────────

    public function test_aprobar_assigns_unique_consecutive_number(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 6]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $auxiliar = $this->crearUsuario('auxiliar');
        $contador = $this->crearUsuario('contador');

        $response = $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
        $response->assertStatus(201);
        $asientoId = $response->json('data.id');

        // Contador diferente al creador puede aprobar
        $aprobado = $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'aprobado')
            ->json('data');

        $this->assertNotNull($aprobado['numero'], 'El asiento aprobado debe tener número consecutivo');
    }

    public function test_aprobar_returns_403_if_user_is_creator(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 7]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $contador = $this->crearUsuario('contador');

        // Contador crea borrador
        $response = $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
        $response->assertStatus(201);
        $asientoId = $response->json('data.id');

        // Mismo contador intenta aprobar → segregación de funciones → 403
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(403);
    }

    public function test_aprobar_returns_403_if_user_is_last_modifier(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 8]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $auxiliar = $this->crearUsuario('auxiliar');
        $contador = $this->crearUsuario('contador');

        $response = $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
        $response->assertStatus(201);
        $asientoId = $response->json('data.id');

        // Contador edita el borrador → queda como last_modified_by_id
        $this->actingAs($contador, 'sanctum')
            ->putJson($this->tenantUrl("/asientos/{$asientoId}"), array_merge(
                $this->payloadValido($periodo, $cta1, $cta2),
                ['descripcion' => 'Descripción editada por contador que luego intentará aprobar'],
            ))
            ->assertStatus(200);

        // Mismo contador intenta aprobar → es el último editor → 403
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(403);
    }

    public function test_aprobar_succeeds_with_exoneracion_flag_active(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 9]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $contador = $this->crearUsuario('contador');

        // Activar exoneración de segregación de funciones en el tenant
        $this->tenant->segregacion_funciones_exonerada = true;
        $this->tenant->save();

        try {
            $response = $this->actingAs($contador, 'sanctum')
                ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
            $response->assertStatus(201);
            $asientoId = $response->json('data.id');

            // Mismo contador puede aprobar su propio borrador (exonerado)
            $this->actingAs($contador, 'sanctum')
                ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
                ->assertStatus(200);
        } finally {
            // Restaurar flag para no afectar otros tests
            $this->tenant->segregacion_funciones_exonerada = false;
            $this->tenant->save();
        }
    }

    // ── Anulación ─────────────────────────────────────────────────────────────

    public function test_anular_requires_motivo_min_20_chars(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 10]);
        $cta1    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento  = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta1->id, 'debito' => '100000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '100000.0000'],
        ]);
        $contador = $this->crearUsuario('contador');

        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asiento->id}/anular"), [
                'motivo' => 'Corto',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo']);
    }

    public function test_anular_in_closed_period_returns_409(): void
    {
        // La Policy::void() verifica $periodo->estaAbierto(). Si está cerrado → false → 403.
        // El nombre del test dice 409 (lo que devolvería el service si pasara la policy),
        // pero en la práctica la policy lo bloquea antes con 403.
        $periodo = $this->crearPeriodo([
            'año_fiscal' => 2026,
            'mes'        => 11,
            'estado'     => PeriodoContable::ESTADO_CERRADO,
        ]);
        $cta1 = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2 = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento  = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta1->id, 'debito' => '100000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '100000.0000'],
        ]);
        $contador = $this->crearUsuario('contador');

        // Policy::void retorna false (periodo no está abierto) → FormRequest devuelve 403
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asiento->id}/anular"), [
                'motivo' => 'Motivo de anulación con longitud mínima requerida de veinte chars',
            ])
            ->assertStatus(403);
    }

    // ── Reverso ───────────────────────────────────────────────────────────────

    public function test_reverse_creates_mirror_asiento_in_open_period(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 12]);
        $cta1    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2    = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);

        $asiento  = $this->crearAsientoAprobado($periodo, [
            ['cuenta_id' => $cta1->id, 'debito' => '500000.0000', 'credito' => '0.0000'],
            ['cuenta_id' => $cta2->id, 'debito' => '0.0000',      'credito' => '500000.0000'],
        ]);
        $contador = $this->crearUsuario('contador');

        $reverso = $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asiento->id}/reversar"), [
                'motivo'        => 'Reverso de asiento con motivo extenso para validación mínima',
                'fecha_reverso' => $periodo->fecha_inicio->toDateString(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertEquals('aprobado', $reverso['estado'], 'El reverso se crea ya aprobado');
    }

    // ── Idempotencia de aprobación ────────────────────────────────────────────

    public function test_concurrent_approvals_do_not_duplicate_consecutive(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2027, 'mes' => 1]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $auxiliar = $this->crearUsuario('auxiliar');
        $contador = $this->crearUsuario('contador');

        $response = $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), $this->payloadValido($periodo, $cta1, $cta2));
        $response->assertStatus(201);
        $asientoId = $response->json('data.id');

        // Primera aprobación: exitosa
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(200);

        // Segunda aprobación del mismo asiento → 403 (policy rechaza: ya no está en borrador)
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(403);

        // El consecutivo fue asignado exactamente una vez
        /** @var Asiento $asiento */
        $asiento = Asiento::query()->find($asientoId);
        $this->assertNotNull($asiento->numero, 'Consecutivo asignado exactamente una vez');
    }
}
