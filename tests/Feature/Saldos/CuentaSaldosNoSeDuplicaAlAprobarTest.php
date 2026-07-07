<?php

declare(strict_types=1);

namespace Tests\Feature\Saldos;

use App\Models\Tenant\CuentaSaldo;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Regresión BUG-003: al aprobar un asiento, los movimientos en cuenta_saldos
 * se duplicaban porque los listeners de eventos de dominio estaban
 * registrados dos veces (array $listen + auto-discovery del framework).
 *
 * Fix: bootstrap/app.php ->withEvents(discover: false).
 *
 * Este test valida desde HTTP end-to-end: crea asiento, aprueba con un usuario
 * contador distinto al creador, y verifica que cuenta_saldos.movimiento_debito
 * y movimiento_credito reflejen EXACTAMENTE el valor de las líneas (no el doble).
 */
final class CuentaSaldosNoSeDuplicaAlAprobarTest extends TenantTestCase
{
    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function crearUsuarioContador(): User
    {
        return User::query()->create([
            'nombre'   => 'Contador',
            'apellido' => 'Test',
            'email'    => 'contador-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);
    }

    public function test_aprobar_asiento_aplica_movimientos_exactos_no_duplicados(): void
    {
        $periodo  = $this->crearPeriodo(['año_fiscal' => 2026, 'mes' => 1]);
        $cuentaDb = $this->crearCuenta(['naturaleza' => 'debito',  'clase' => 1]);
        $cuentaCr = $this->crearCuenta(['naturaleza' => 'credito', 'clase' => 2]);
        $contador = $this->crearUsuarioContador();

        $monto = 1_234_567;

        // 1. Crear asiento como adminUser (que no es contador → no podrá aprobar)
        $crear = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->tenantUrl('/asientos'), [
                'fecha'            => $periodo->fecha_inicio->toDateString(),
                'tipo_comprobante' => 'DB',
                'descripcion'      => 'Test regresión BUG-003 — partida doble simple ' . $monto,
                'lineas' => [
                    ['cuenta_contable_id' => $cuentaDb->id, 'debito' => $monto, 'credito' => 0],
                    ['cuenta_contable_id' => $cuentaCr->id, 'debito' => 0,      'credito' => $monto],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'borrador');

        $asientoId = $crear->json('data.id');
        $this->assertIsString($asientoId);

        // 2. Aprobar el asiento con un usuario contador distinto al creador
        $this->actingAs($contador, 'sanctum')
            ->postJson($this->tenantUrl("/asientos/{$asientoId}/aprobar"), [])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'aprobado');

        // 3. Verificar movimientos EXACTOS en cuenta_saldos
        $saldoDb = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cuentaDb->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $saldoCr = CuentaSaldo::query()
            ->where('cuenta_contable_id', $cuentaCr->id)
            ->where('periodo_id', $periodo->id)
            ->first();

        $this->assertNotNull($saldoDb, 'No se creó saldo para la cuenta DB');
        $this->assertNotNull($saldoCr, 'No se creó saldo para la cuenta CR');

        $this->assertSame(
            (float) $monto,
            (float) $saldoDb->movimiento_debito,
            sprintf(
                'BUG-003: movimiento_debito esperado %d pero quedó %s. '
                . '¿Listeners registrados dos veces?',
                $monto,
                (string) $saldoDb->movimiento_debito,
            ),
        );

        $this->assertSame(
            (float) $monto,
            (float) $saldoCr->movimiento_credito,
            sprintf(
                'BUG-003: movimiento_credito esperado %d pero quedó %s. '
                . '¿Listeners registrados dos veces?',
                $monto,
                (string) $saldoCr->movimiento_credito,
            ),
        );

        // Sanidad adicional: el lado contrario debe quedar en cero
        $this->assertSame(0.0, (float) $saldoDb->movimiento_credito);
        $this->assertSame(0.0, (float) $saldoCr->movimiento_debito);
    }
}
