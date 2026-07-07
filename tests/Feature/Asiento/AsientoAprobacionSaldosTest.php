<?php

declare(strict_types=1);

namespace Tests\Feature\Asiento;

use App\Models\AuditLog;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaSaldo;
use App\Models\Tenant\PeriodoContable;
use App\Models\Tenant\CuentaContable;
use App\Models\User;
use App\Services\Asiento\AsientoOperacionInvalidaException;
use App\Services\Asiento\AsientoService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Reproducción del bug observado: al aprobar un asiento vía
 * POST /asientos/{id}/aprobar, los movimientos en `cuenta_saldos`
 * quedan al DOBLE de la suma de `asiento_items.debito`/`.credito`.
 *
 * Este test ejecuta el flujo HTTP completo (idéntico al usado en producción)
 * y verifica la invariante: cuenta_saldos = asiento_items, exacto.
 *
 * Si la hipótesis de race condition es correcta, este test con UNA sola
 * llamada al endpoint debe PASAR — confirmando que la duplicación viene
 * de doble POST (front, retry, etc.), no de doble ejecución interna.
 *
 * Si el test FALLA con una sola llamada, hay un bug de doble dispatch interno
 * que aún no hemos localizado.
 */
class AsientoAprobacionSaldosTest extends TenantTestCase
{
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

    public function test_aprobacion_no_duplica_movimientos_en_cuenta_saldos(): void
    {
        $periodo = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 5]);

        // Asiento de apertura: 50M caja + 20M equipos = 70M débito, 70M capital crédito
        $caja    = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1, 'codigo' => '111005-' . Str::random(4)]);
        $equipos = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1, 'codigo' => '143005-' . Str::random(4)]);
        $capital = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 3, 'codigo' => '310505-' . Str::random(4)]);

        $auxiliar = $this->crearUsuario('auxiliar');
        $contador = $this->crearUsuario('contador');

        // Paso 1: crear borrador
        $response = $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), [
                'fecha'            => $periodo->fecha_inicio->toDateString(),
                'tipo_comprobante' => 'CI',
                'descripcion'      => 'Asiento de apertura — capital social inicial',
                'lineas'           => [
                    ['cuenta_contable_id' => $caja->id,    'debito' => 50000000, 'credito' => 0],
                    ['cuenta_contable_id' => $equipos->id, 'debito' => 20000000, 'credito' => 0],
                    ['cuenta_contable_id' => $capital->id, 'debito' => 0,        'credito' => 70000000],
                ],
            ])
            ->assertStatus(201);

        $asientoId = $response->json('data.id');

        // Paso 2: aprobar (UN solo POST)
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'aprobado');

        // Paso 3: verificar invariante — cuenta_saldos coincide con asiento_items
        $saldoCaja = CuentaSaldo::query()
            ->where('cuenta_contable_id', $caja->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $saldoEquipos = CuentaSaldo::query()
            ->where('cuenta_contable_id', $equipos->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $saldoCapital = CuentaSaldo::query()
            ->where('cuenta_contable_id', $capital->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $this->assertNotNull($saldoCaja,    'Debe existir saldo para caja tras aprobar');
        $this->assertNotNull($saldoEquipos, 'Debe existir saldo para equipos tras aprobar');
        $this->assertNotNull($saldoCapital, 'Debe existir saldo para capital tras aprobar');

        $this->assertEquals(
            '50000000.0000',
            $saldoCaja->movimiento_debito,
            'movimiento_debito de caja debe ser 50M (no 100M). Si es 100M, el listener corrió 2 veces.',
        );
        $this->assertEquals(
            '20000000.0000',
            $saldoEquipos->movimiento_debito,
            'movimiento_debito de equipos debe ser 20M (no 40M).',
        );
        $this->assertEquals(
            '70000000.0000',
            $saldoCapital->movimiento_credito,
            'movimiento_credito de capital debe ser 70M (no 140M).',
        );
    }

    public function test_doble_invocacion_del_service_rechaza_la_segunda_sin_duplicar_saldos(): void
    {
        // Regresión: aún si por race condition o doble-click un segundo flujo llega
        // hasta el service, el guard `esBorrador()` bajo lockForUpdate debe rechazarlo
        // (AsientoOperacionInvalidaException) y los saldos NO deben acumularse al doble.
        //
        // Se invoca el service directamente (no HTTP) porque la AsientoPolicy ya bloquea
        // con 403 cualquier estado != borrador antes de llegar al service, pero ESA capa
        // hace el SELECT sin lock — no protege contra concurrencia real. La invariante
        // verdadera vive en AsientoService::aprobar() y este test la cubre.
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 7]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $contador = $this->crearUsuario('contador');

        // Crear borrador a mano (con líneas) para evitar dependencia del flujo HTTP
        $asiento = Asiento::withoutEvents(function () use ($periodo, $cta1, $cta2, $contador) {
            $a = new Asiento();
            $a->forceFill([
                'tipo_comprobante' => 'DB',
                'comprobante'      => 'Diario Básico',
                'numero_documento' => 'TEST-001',
                'fecha'            => $periodo->fecha_inicio,
                'periodo_id'       => $periodo->id,
                'año_fiscal'       => $periodo->año_fiscal,
                'descripcion'      => 'Test guard concurrencia',
                'estado'           => Asiento::ESTADO_BORRADOR,
                'created_by_id'    => $this->adminUser->id,
            ]);
            $a->save();
            $a->lineas()->create([
                'cuenta_id' => $cta1->id, 'debito' => '500000.0000', 'credito' => '0.0000',
            ]);
            $a->lineas()->create([
                'cuenta_id' => $cta2->id, 'debito' => '0.0000', 'credito' => '500000.0000',
            ]);
            return $a;
        });

        /** @var AsientoService $service */
        $service = $this->app->make(AsientoService::class);

        // 1ra invocación: aprueba normal
        $service->aprobar($asiento, $contador);

        // 2da invocación: debe rechazarse — el lockForUpdate re-lee y ve estado='aprobado'
        $caught = false;
        try {
            $service->aprobar($asiento, $contador);
        } catch (AsientoOperacionInvalidaException $e) {
            $caught = true;
            $this->assertStringContainsString('borrador', $e->getMessage());
        }
        $this->assertTrue($caught, 'La segunda invocación debe lanzar AsientoOperacionInvalidaException.');

        // Saldos siguen reflejando UNA sola aprobación (500K, no 1M)
        $saldo = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cta1->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $this->assertNotNull($saldo);
        $this->assertEquals(
            '500000.0000',
            $saldo->movimiento_debito,
            'Tras rechazo de la 2da aprobación, los saldos NO deben haberse acumulado.',
        );
    }

    public function test_aprobacion_genera_un_solo_audit_log_approved(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 6]);
        $cta1     = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cta2     = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $auxiliar = $this->crearUsuario('auxiliar');
        $contador = $this->crearUsuario('contador');

        $response = $this->actingAs($auxiliar, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), [
                'fecha'            => $periodo->fecha_inicio->toDateString(),
                'tipo_comprobante' => 'DB',
                'descripcion'      => 'Asiento para verificar conteo de audit_logs',
                'lineas'           => [
                    ['cuenta_contable_id' => $cta1->id, 'debito' => 100000, 'credito' => 0],
                    ['cuenta_contable_id' => $cta2->id, 'debito' => 0,      'credito' => 100000],
                ],
            ])
            ->assertStatus(201);

        $asientoId = $response->json('data.id');

        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(200);

        // Exactamente UN audit_log de 'asiento.approved' para este asiento
        $approvedCount = AuditLog::query()
            ->where('action', 'asiento.approved')
            ->where('auditable_id', $asientoId)
            ->count();

        $this->assertEquals(
            1,
            $approvedCount,
            "Debe haber exactamente 1 audit_log 'asiento.approved'. Encontrados: {$approvedCount}. "
            . 'Si son 2, el listener RecordAuditOnAsientoAprobado se ejecutó dos veces.',
        );
    }
}
