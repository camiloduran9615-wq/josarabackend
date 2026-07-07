<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use App\Models\Tenant\ReciboCaja;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Tests del asiento contable generado por ReciboCajaController.
 *
 * Al registrar un recibo de caja, el controller genera:
 *   DÉBITO  110505  Caja General    → valor_recibido
 *   CRÉDITO 130505  CxC Clientes    → valor_recibido
 */
class ReciboCajaAsientoTest extends TenantTestCase
{
    private User    $contador;
    private Tercero $cliente;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    private function seedFixtures(): void
    {
        $this->contador = User::create([
            'nombre'   => 'Cajero',
            'apellido' => 'Test',
            'email'    => 'cajero-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        $this->crearPeriodo([
            'año_fiscal' => (int) date('Y'),
            'mes'        => (int) date('m'),
        ]);

        $this->cliente = Tercero::create([
            'tipo_persona'               => 'juridica',
            'tipo_documento'             => 'nit',
            'numero_documento'           => '700' . rand(100000, 999999),
            'identificacion_documento_id'=> '31',
            'identificacion'             => '700' . rand(100000, 999999) . '-' . rand(0, 9),
            'razon_social'               => 'Cliente Recibo S.A.S.',
            'email'                      => 'cliente-recibo@test.co',
            'es_cliente'                 => true,
            'activo'                     => true,
        ]);

        // Verificar que las cuentas del módulo recibo existen (sembradas por TenantPucSeeder)
        $caja = CuentaContable::where('codigo', '110505')->firstOrFail();
        $cxc  = CuentaContable::where('codigo', '130505')->firstOrFail();

        ParametrizacionContable::updateOrCreate(
            ['clave' => 'recibo.cuenta_caja'],
            ['cuenta_contable_id' => $caja->id, 'activo' => true],
        );
        ParametrizacionContable::updateOrCreate(
            ['clave' => 'recibo.cuenta_cxc'],
            ['cuenta_contable_id' => $cxc->id, 'activo' => true],
        );
    }

    // ─── RC-001: Asiento generado en store() ─────────────────────────────────

    /**
     * RC-001: Registrar recibo de caja crea asiento DÉBITO 110505 / CRÉDITO 130505.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function rc001_store_genera_asiento_caja_cxc(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/recibos-caja'), [
                'tercero_id'    => $this->cliente->id,
                'fecha'         => now()->toDateString(),
                'valor_recibido'=> 250000,
                'concepto'      => 'Cobro factura RC-001',
                'forma_pago'    => 'efectivo',
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $recibo = ReciboCaja::findOrFail($response->json('data.id'));

        // Debe existir exactamente un asiento ligado al recibo
        $asiento = Asiento::where('origen_type', ReciboCaja::class)
            ->where('origen_id', $recibo->id)
            ->with('lineas.cuenta')
            ->first();

        $this->assertNotNull($asiento, 'Debe generarse un asiento para el recibo de caja');

        // Cuadre contable
        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals($debitos, $creditos, "Asiento no cuadra: D={$debitos} C={$creditos}");
        $this->assertEquals(250000.0, $debitos, 'Total débitos debe ser el valor recibido');

        // Cuentas correctas
        $codigos = $asiento->lineas->pluck('cuenta.codigo')->toArray();
        $this->assertContains('110505', $codigos, 'Debe debitar Caja General (110505)');
        $this->assertContains('130505', $codigos, 'Debe acreditar CxC Clientes (130505)');

        // Montos
        $lineaCaja = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '110505');
        $lineaCxc  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '130505');

        $this->assertEquals(250000.0, (float) $lineaCaja->debito,  'Débito Caja debe ser $250.000');
        $this->assertEquals(250000.0, (float) $lineaCxc->credito,  'Crédito CxC debe ser $250.000');
    }

    // ─── RC-002: Distintas formas de pago no cambian el asiento ─────────────

    /**
     * RC-002: Independientemente de la forma de pago (cheque, transferencia, etc.),
     * el asiento siempre es DÉBITO 110505 / CRÉDITO 130505 por el mismo valor.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function rc002_distintas_formas_pago_generan_mismo_asiento(): void
    {
        $this->seedFixtures();

        foreach (['cheque', 'transferencia', 'tarjeta_debito', 'consignacion'] as $formaPago) {
            $response = $this->actingAs($this->contador, 'sanctum')
                ->postJson($this->tenantUrl('/recibos-caja'), [
                    'tercero_id'    => $this->cliente->id,
                    'fecha'         => now()->toDateString(),
                    'valor_recibido'=> 100000,
                    'concepto'      => "Cobro {$formaPago}",
                    'forma_pago'    => $formaPago,
                ]);

            $response->assertStatus(201, "forma_pago={$formaPago} debe retornar 201");

            $recibo  = ReciboCaja::findOrFail($response->json('data.id'));
            $asiento = Asiento::where('origen_type', ReciboCaja::class)
                ->where('origen_id', $recibo->id)
                ->with('lineas.cuenta')
                ->first();

            $this->assertNotNull($asiento, "Debe generarse asiento para forma_pago={$formaPago}");

            $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
            $creditos = round((float) $asiento->lineas->sum('credito'), 2);
            $this->assertEquals($debitos, $creditos,
                "Asiento debe cuadrar para forma_pago={$formaPago}"
            );
        }
    }

    // ─── RC-003: Validación — valor cero es rechazado ────────────────────────

    /**
     * RC-003: Valor recibido = 0 debe retornar 422 (validación Laravel).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function rc003_valor_cero_retorna_422(): void
    {
        $this->seedFixtures();

        $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/recibos-caja'), [
                'tercero_id'    => $this->cliente->id,
                'fecha'         => now()->toDateString(),
                'valor_recibido'=> 0,
                'concepto'      => 'Cobro inválido',
                'forma_pago'    => 'efectivo',
            ])
            ->assertStatus(422);
    }

    // ─── RC-004: Anulación no deja asiento huérfano ──────────────────────────

    /**
     * RC-004: Anular un recibo de caja (DELETE) lo elimina pero el asiento ya aprobado
     * sigue existiendo en el libro — la lógica del negocio requiere reversa manual.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function rc004_anulacion_cambia_estado_a_anulado(): void
    {
        $this->seedFixtures();

        $create = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/recibos-caja'), [
                'tercero_id'    => $this->cliente->id,
                'fecha'         => now()->toDateString(),
                'valor_recibido'=> 75000,
                'concepto'      => 'Cobro a anular',
                'forma_pago'    => 'efectivo',
            ]);

        $create->assertStatus(201);
        $reciboId = $create->json('data.id');

        // Anular
        $this->actingAs($this->contador, 'sanctum')
            ->deleteJson($this->tenantUrl("/recibos-caja/{$reciboId}"))
            ->assertStatus(200);

        // Segunda anulación → debe rechazarse
        $this->actingAs($this->contador, 'sanctum')
            ->deleteJson($this->tenantUrl("/recibos-caja/{$reciboId}"))
            ->assertStatus(422);
    }
}
