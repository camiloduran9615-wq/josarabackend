<?php

declare(strict_types=1);

namespace Tests\Feature\Nomina;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\LiquidacionNomina;
use Tests\TenantTestCase;

/**
 * BUG-013 — Aportes empleador (Ley 100), exoneración Ley 1607/2012
 * y generación del asiento contable de nómina al aprobar.
 *
 * Cubre el 30% restante del módulo de nómina:
 *   • Líneas tipo='aporte_empleador' (EMP_SALUD, EMP_PENSION, EMP_ARL,
 *     EMP_CCF, EMP_SENA, EMP_ICBF) en la liquidación.
 *   • Exoneración de SALUD/SENA/ICBF cuando salario indiv. ≤ 10 SMMLV.
 *   • Asiento contable con partida doble verificada al aprobar.
 */
class NominaAportesEmpleadorTest extends TenantTestCase
{
    private const SMMLV_2026 = 1_423_500.0;

    private function url(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    /** @return object{id:string} */
    private function crearEmpleado(): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/empleados'), [
                'tipo_documento'   => 'CC',
                'numero_documento' => (string) rand(10_000_000, 99_999_999),
                'primer_nombre'    => 'María',
                'primer_apellido'  => 'González',
            ]);
        $r->assertCreated();
        return (object) $r->json('data');
    }

    private function crearContrato(string $empleadoId, float $salario): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/contratos'), [
                'empleado_id'     => $empleadoId,
                'tipo_contrato'   => 'indefinido',
                'tipo_trabajador' => 'dependiente',
                'fecha_inicio'    => '2026-01-01',
                'salario_basico'  => $salario,
            ])->assertCreated();
    }

    /** @return object{id:string} */
    private function crearPeriodoNomina(int $mes): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/periodos-nomina'), [
                'tipo'         => 'mensual',
                'fecha_inicio' => "2026-" . str_pad((string) $mes, 2, '0', STR_PAD_LEFT) . '-01',
                'fecha_fin'    => date('Y-m-t', mktime(0, 0, 0, $mes, 1, 2026)),
                'año'          => 2026,
                'mes'          => $mes,
            ]);
        $r->assertCreated();
        return (object) $r->json('data');
    }

    private function liquidar(string $empleadoId, string $periodoId): array
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleadoId}/{$periodoId}"));
        $r->assertCreated();
        return $r->json('data');
    }

    // ── Exoneración Ley 1607 — salario individual ≤ 10 SMMLV ─────────────

    public function test_exoneracion_ley1607_si_salario_le_10_smmlv(): void
    {
        $empleado = $this->crearEmpleado();
        // 5 SMMLV → ≤ 10 SMMLV → EXONERADO
        $this->crearContrato($empleado->id, 5 * self::SMMLV_2026);
        $periodo = $this->crearPeriodoNomina(2);

        $liq = $this->liquidar($empleado->id, $periodo->id);
        $aportes = collect($liq['lineas'])->where('tipo', 'aporte_empleador');

        $codigos = $aportes->pluck('concepto.codigo')->all();

        // No deben aparecer SALUD, SENA, ICBF (exonerados)
        $this->assertNotContains('EMP_SALUD', $codigos, 'EMP_SALUD debe estar EXONERADO con salario ≤ 10 SMMLV');
        $this->assertNotContains('EMP_SENA',  $codigos, 'EMP_SENA debe estar EXONERADO con salario ≤ 10 SMMLV');
        $this->assertNotContains('EMP_ICBF',  $codigos, 'EMP_ICBF debe estar EXONERADO con salario ≤ 10 SMMLV');

        // Pensión, ARL, CCF SIEMPRE aplican
        $this->assertContains('EMP_PENSION', $codigos);
        $this->assertContains('EMP_ARL',     $codigos);
        $this->assertContains('EMP_CCF',     $codigos);
    }

    public function test_tarifas_plenas_si_salario_supera_10_smmlv(): void
    {
        $empleado = $this->crearEmpleado();
        // 12 SMMLV → > 10 SMMLV → NO exonerado
        $salario = 12 * self::SMMLV_2026;
        $this->crearContrato($empleado->id, $salario);
        $periodo = $this->crearPeriodoNomina(3);

        $liq = $this->liquidar($empleado->id, $periodo->id);
        $aportes = collect($liq['lineas'])->where('tipo', 'aporte_empleador');

        // Los 6 aportes empleador deben existir
        foreach (['EMP_SALUD', 'EMP_PENSION', 'EMP_ARL', 'EMP_CCF', 'EMP_SENA', 'EMP_ICBF'] as $codigo) {
            $linea = $aportes->firstWhere('concepto.codigo', $codigo);
            $this->assertNotNull($linea, "Falta línea {$codigo} cuando salario > 10 SMMLV");
            $this->assertGreaterThan(0, (float) $linea['valor_total']);
        }

        // Verificar tarifas — IBC se topa en 25 SMMLV, pero 12 SMMLV está dentro
        $ibc = $salario;
        $tolerancia = 1.0;

        $salud = (float) $aportes->firstWhere('concepto.codigo', 'EMP_SALUD')['valor_total'];
        $this->assertEqualsWithDelta($ibc * 0.085, $salud, $tolerancia, 'EMP_SALUD debe ser 8.5% de IBC');

        $pension = (float) $aportes->firstWhere('concepto.codigo', 'EMP_PENSION')['valor_total'];
        $this->assertEqualsWithDelta($ibc * 0.12, $pension, $tolerancia, 'EMP_PENSION debe ser 12% de IBC');

        $sena = (float) $aportes->firstWhere('concepto.codigo', 'EMP_SENA')['valor_total'];
        $this->assertEqualsWithDelta($ibc * 0.02, $sena, $tolerancia, 'EMP_SENA debe ser 2% de IBC');

        $icbf = (float) $aportes->firstWhere('concepto.codigo', 'EMP_ICBF')['valor_total'];
        $this->assertEqualsWithDelta($ibc * 0.03, $icbf, $tolerancia, 'EMP_ICBF debe ser 3% de IBC');

        $ccf = (float) $aportes->firstWhere('concepto.codigo', 'EMP_CCF')['valor_total'];
        $this->assertEqualsWithDelta($ibc * 0.04, $ccf, $tolerancia, 'EMP_CCF debe ser 4% de IBC');
    }

    // ── Asiento contable al aprobar — partida doble ──────────────────────

    public function test_aprobar_genera_asiento_balanceado(): void
    {
        $empleado = $this->crearEmpleado();
        $this->crearContrato($empleado->id, 2_000_000);
        $periodo = $this->crearPeriodoNomina(4);

        $liq = $this->liquidar($empleado->id, $periodo->id);

        $aprobacion = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"));
        $aprobacion->assertOk();

        /** @var LiquidacionNomina $liqDb */
        $liqDb = LiquidacionNomina::findOrFail($liq['id']);
        $this->assertNotNull($liqDb->asiento_id, 'aprobar() debe generar asiento contable');

        /** @var Asiento $asiento */
        $asiento = Asiento::with('lineas')->findOrFail($liqDb->asiento_id);

        $this->assertSame(Asiento::ESTADO_APROBADO, $asiento->estado);
        $this->assertSame('NOM', $asiento->tipo_comprobante);

        $this->assertGreaterThanOrEqual(2, $asiento->lineas->count(), 'partida doble requiere ≥ 2 líneas');
        $this->assertTrue(
            $asiento->balanceado(),
            'El asiento no balancea: ∑D=' . $asiento->totalDebito() . ', ∑C=' . $asiento->totalCredito(),
        );
    }

    public function test_aprobar_es_idempotente_no_duplica_asiento(): void
    {
        $empleado = $this->crearEmpleado();
        $this->crearContrato($empleado->id, 2_000_000);
        $periodo = $this->crearPeriodoNomina(5);

        $liq = $this->liquidar($empleado->id, $periodo->id);

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertOk();

        // Segunda aprobación → 409 (ya aprobada). No debe generar segundo asiento.
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertStatus(409);

        $count = Asiento::query()
            ->where('origen_type', LiquidacionNomina::class)
            ->where('origen_id', $liq['id'])
            ->where('tipo_movimiento', Asiento::TIPO_NORMAL)
            ->count();

        $this->assertSame(1, $count, 'No debe haber asientos duplicados');
    }

    public function test_asiento_credita_pasivos_laborales(): void
    {
        $empleado = $this->crearEmpleado();
        $this->crearContrato($empleado->id, 2_000_000);
        $periodo = $this->crearPeriodoNomina(6);

        $liq = $this->liquidar($empleado->id, $periodo->id);

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertOk();

        $liqDb = LiquidacionNomina::findOrFail($liq['id']);
        $asiento = Asiento::with('lineas.cuenta')->findOrFail($liqDb->asiento_id);

        // Indexar líneas por código de cuenta
        $porCuenta = $asiento->lineas->mapWithKeys(
            fn ($l) => [$l->cuenta->codigo => $l],
        );

        // Pasivos laborales obligatorios (provisiones — sólo lado crédito):
        $pasivosCredito = ['250505', '251005', '251505', '252005', '252505'];
        foreach ($pasivosCredito as $codigo) {
            $this->assertTrue(
                $porCuenta->has($codigo),
                "El asiento debe incluir un crédito a la cuenta {$codigo}. Cuentas presentes: "
                . implode(',', $porCuenta->keys()->all()),
            );
            $this->assertGreaterThan(0, (float) $porCuenta[$codigo]->credito);
        }

        // Aportes parafiscales (237xxx) — ARL/CCF siempre aplican
        $this->assertTrue($porCuenta->has('237015'), 'Falta crédito ARL (237015)');
        $this->assertTrue($porCuenta->has('237030'), 'Falta crédito CCF (237030)');

        // Gastos de personal (lado débito) — sueldos siempre
        $this->assertTrue($porCuenta->has('510506'), 'Falta débito a 510506 sueldos');
        $this->assertGreaterThan(0, (float) $porCuenta['510506']->debito);
    }

    public function test_xml_incluye_seccion_aportes_empleador(): void
    {
        $empleado = $this->crearEmpleado();
        // > 10 SMMLV para que aparezcan todos los aportes (sin exoneración)
        $this->crearContrato($empleado->id, 12 * self::SMMLV_2026);
        $periodo = $this->crearPeriodoNomina(7);

        $liq = $this->liquidar($empleado->id, $periodo->id);

        $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url("/liquidaciones/{$liq['id']}/xml"))
            ->assertOk();

        $xml = \App\Models\Tenant\NominaDian::where('liquidacion_id', $liq['id'])
            ->firstOrFail()->xml_generado;

        $this->assertStringContainsString('<AportesEmpleador>',  $xml);
        $this->assertStringContainsString('<SaludEmpleador',     $xml);
        $this->assertStringContainsString('<PensionEmpleador',   $xml);
        $this->assertStringContainsString('<ARLEmpleador',       $xml);
        $this->assertStringContainsString('<CajaCompensacion',   $xml);
        $this->assertStringContainsString('<SENA',               $xml);
        $this->assertStringContainsString('<ICBF',               $xml);

        // Sección Devengados ahora incluye provisiones
        $this->assertStringContainsString('<Cesantias',          $xml);
        $this->assertStringContainsString('<CesantiasIntereses', $xml);
        $this->assertStringContainsString('<Primas',             $xml);
        $this->assertStringContainsString('<Vacaciones',         $xml);
    }
}
