<?php

declare(strict_types=1);

namespace Tests\Feature\Nomina;

use Tests\TenantTestCase;

/**
 * Tests de Nómina Electrónica DIAN.
 *
 * Cubre: CRUD empleados, contratos, periodos de nómina y liquidación básica.
 * Los ConceptoNomina (BASICO, DED_SALUD, DED_PENSION) deben existir via
 * TenantConceptosNominaSeeder (correr: tenants:seed --class=TenantConceptosNominaSeeder).
 */
class NominaTest extends TenantTestCase
{
    private function url(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    /** Crea un empleado via HTTP y retorna el objeto de datos. */
    private function crearEmpleadoHttp(array $overrides = []): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/empleados'), array_merge([
                'tipo_documento'   => 'CC',
                'numero_documento' => (string) rand(10000000, 99999999),
                'primer_nombre'    => 'Juan',
                'primer_apellido'  => 'Pérez',
            ], $overrides));
        $r->assertCreated();
        return (object) $r->json('data');
    }

    /** Crea un contrato activo via HTTP para el empleado dado. */
    private function crearContratoHttp(string $empleadoId, float $salario = 1_500_000): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/contratos'), [
                'empleado_id'     => $empleadoId,
                'tipo_contrato'   => 'indefinido',
                'tipo_trabajador' => 'dependiente',
                'fecha_inicio'    => '2026-01-01',
                'salario_basico'  => $salario,
            ]);
        $r->assertCreated();
        return (object) $r->json('data');
    }

    /** Crea un periodo de nómina mensual via HTTP. */
    private function crearPeriodoNominaHttp(int $año = 2026, int $mes = 3): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/periodos-nomina'), [
                'tipo'         => 'mensual',
                'fecha_inicio' => "{$año}-" . str_pad((string) $mes, 2, '0', STR_PAD_LEFT) . '-01',
                'fecha_fin'    => date('Y-m-t', mktime(0, 0, 0, $mes, 1, $año)),
                'año'          => $año,
                'mes'          => $mes,
            ]);
        $r->assertCreated();
        return (object) $r->json('data');
    }

    // ── Empleados ─────────────────────────────────────────────────────────────

    public function test_listar_empleados(): void
    {
        $this->crearEmpleadoHttp();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url('/empleados'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data']);

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_crear_empleado(): void
    {
        $doc = (string) rand(10000000, 99999999);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/empleados'), [
                'tipo_documento'   => 'CC',
                'numero_documento' => $doc,
                'primer_nombre'    => 'Maria',
                'primer_apellido'  => 'García',
                'email'            => 'maria@empresa.com',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.primer_nombre', 'Maria');

        $this->assertDatabaseHas('empleados', ['numero_documento' => $doc]);
    }

    public function test_crear_empleado_requiere_campos_obligatorios(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/empleados'), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo_documento', 'numero_documento', 'primer_nombre', 'primer_apellido']);
    }

    public function test_crear_empleado_documento_unico(): void
    {
        $doc = (string) rand(10000000, 99999999);

        $this->crearEmpleadoHttp(['numero_documento' => $doc]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/empleados'), [
                'tipo_documento'   => 'CC',
                'numero_documento' => $doc,
                'primer_nombre'    => 'Otro',
                'primer_apellido'  => 'Nombre',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['numero_documento']);
    }

    public function test_actualizar_empleado(): void
    {
        $empleado = $this->crearEmpleadoHttp();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson($this->url("/empleados/{$empleado->id}"), [
                'primer_nombre' => 'Carlos',
                'telefono'      => '3209876543',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.primer_nombre', 'Carlos');
    }

    // ── Contratos ─────────────────────────────────────────────────────────────

    public function test_crear_contrato_laboral(): void
    {
        $empleado = $this->crearEmpleadoHttp();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/contratos'), [
                'empleado_id'     => $empleado->id,
                'tipo_contrato'   => 'indefinido',
                'tipo_trabajador' => 'dependiente',
                'fecha_inicio'    => '2026-01-01',
                'salario_basico'  => 1_500_000,
                'cargo'           => 'Desarrollador',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo_contrato', 'indefinido');
    }

    public function test_salario_minimo_contrato(): void
    {
        $empleado = $this->crearEmpleadoHttp();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/contratos'), [
                'empleado_id'     => $empleado->id,
                'tipo_contrato'   => 'indefinido',
                'tipo_trabajador' => 'dependiente',
                'fecha_inicio'    => '2026-01-01',
                'salario_basico'  => 500_000, // menos del mínimo (1.300.000)
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['salario_basico']);
    }

    // ── Periodos de nómina ────────────────────────────────────────────────────

    public function test_crear_periodo_nomina(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/periodos-nomina'), [
                'tipo'         => 'mensual',
                'fecha_inicio' => '2026-03-01',
                'fecha_fin'    => '2026-03-31',
                'año'          => 2026,
                'mes'          => 3,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'mensual');
    }

    // ── Liquidación ───────────────────────────────────────────────────────────

    public function test_liquidar_empleado(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 2_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 4);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $liq = $response->json('data');
        $this->assertEquals('borrador', $liq['estado']);
        $this->assertGreaterThan(0, $liq['total_devengado']);
        $this->assertGreaterThan(0, $liq['total_deduccion']);
        $this->assertGreaterThan(0, $liq['neto_pagar']);
        $this->assertEqualsWithDelta(
            $liq['total_devengado'] - $liq['total_deduccion'],
            $liq['neto_pagar'],
            0.01
        );
    }

    public function test_liquidar_idempotente(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 5);

        // Primera liquidación
        $r1 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $r1->assertCreated();

        // Segunda llamada — debe retornar la misma liquidación
        $r2 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $r2->assertCreated();

        $this->assertEquals($r1->json('data.id'), $r2->json('data.id'));
    }

    public function test_liquidar_sin_contrato_activo_retorna_422(): void
    {
        $empleado = $this->crearEmpleadoHttp(); // sin contrato
        $periodo  = $this->crearPeriodoNominaHttp(2026, 6);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));

        $response->assertUnprocessable();
    }

    // ── BUG-007: liquidador completo (aux. transporte, provisiones, aportes empleador) ──

    /**
     * BUG-007 (a): el liquidador debe sumar automáticamente AUXILIO DE
     * TRANSPORTE al devengado cuando el salario es <= 2 SMMLV.
     *
     * Valor 2026: $200.000 (Decreto MinTrabajo). Validamos que la línea
     * AUX_TRANSPORTE exista y que el devengado total sea salario + aux.
     */
    public function test_bug007_aplica_auxilio_transporte_si_salario_le_2_smmlv(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        // 1.500.000 < 2 SMMLV (2.847.000) → aplica aux. transporte
        $this->crearContratoHttp($empleado->id, 1_500_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 9);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $response->assertCreated();

        $liq = $response->json('data');
        $lineas = collect($liq['lineas'] ?? []);

        $auxLinea = $lineas->first(
            fn ($l) => ($l['concepto']['codigo'] ?? '') === 'AUX_TRANSPORTE'
        );

        $this->assertNotNull(
            $auxLinea,
            'BUG-007: no se agregó la línea de auxilio de transporte. '
            . 'El liquidador debe aplicarlo automáticamente si salario <= 2 SMMLV.',
        );

        $auxValor = (float) ($auxLinea['valor_total'] ?? 0);
        $this->assertGreaterThan(
            150_000,
            $auxValor,
            'Aux. transporte 2026 debe ser ~$200.000 (verifica config si difiere).',
        );

        // Devengado debe incluir aux. transporte
        $this->assertGreaterThanOrEqual(
            1_500_000 + 150_000,
            (float) $liq['total_devengado'],
            'total_devengado debe incluir salario + aux. transporte',
        );
    }

    /**
     * BUG-007 (b): empleado con salario > 2 SMMLV NO recibe aux. transporte.
     */
    public function test_bug007_no_aplica_auxilio_si_salario_supera_2_smmlv(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        // 3.500.000 > 2 SMMLV (2.847.000) → NO aplica aux. transporte
        $this->crearContratoHttp($empleado->id, 3_500_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 10);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $response->assertCreated();

        $liq = $response->json('data');
        $lineas = collect($liq['lineas'] ?? []);

        $auxLinea = $lineas->first(
            fn ($l) => ($l['concepto']['codigo'] ?? '') === 'AUX_TRANSPORTE'
        );

        $this->assertNull(
            $auxLinea,
            'BUG-007: aux. transporte NO debe aplicarse si salario > 2 SMMLV.',
        );
    }

    /**
     * BUG-007 (c): el liquidador debe generar las PROVISIONES laborales
     * (cesantías, intereses cesantías, prima, vacaciones) como líneas
     * separadas. Sin esto, el XML UBL DIAN queda incompleto y los pasivos
     * laborales no se reflejan en el balance.
     */
    public function test_bug007_genera_provisiones_laborales(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 2_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 11);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $response->assertCreated();

        $liq = $response->json('data');
        $lineas = collect($liq['lineas'] ?? []);
        $codigos = $lineas->pluck('concepto.codigo')->filter()->values()->all();

        $provisionesEsperadas = ['CESANTIAS', 'INT_CESANTIAS', 'PRIMA', 'VACACIONES'];
        foreach ($provisionesEsperadas as $codigo) {
            $this->assertContains(
                $codigo,
                $codigos,
                "BUG-007: la liquidación no incluye provisión '{$codigo}'. "
                . "Codigos presentes: " . implode(',', $codigos),
            );
        }

        // Cesantías: 8.33% sobre (salario + aux. transp si aplica)
        // Para 2.000.000 sin aux (>2 SMMLV no: 2.000.000 <= 2.847.000 sí aplica aux)
        // Base = 2.000.000 + 200.000 = 2.200.000
        // Cesantías = 2.200.000 × 8.33% ≈ 183.260 ó usando fórmula salario × días/360
        $cesantias = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'CESANTIAS');
        $this->assertNotNull($cesantias);
        $this->assertGreaterThan(
            150_000,
            (float) $cesantias['valor_total'],
            'Cesantías debe ser ~8.33% sobre devengado (salario + aux)',
        );
    }

    /**
     * BUG-013 (a): Empleado con salario <= 10 SMMLV → aplica exoneración
     * Ley 1607: SALUD, SENA, ICBF se omiten (no aparecen como línea o
     * aparecen con valor 0). Pensión, ARL, CCF se calculan normalmente.
     */
    public function test_bug013_aportes_empleador_con_exoneracion_ley1607(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        // 1.500.000 < 10 SMMLV (14.235.000) → aplica exoneración
        $this->crearContratoHttp($empleado->id, 1_500_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 12);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $response->assertCreated();

        $liq = $response->json('data');
        $lineas = collect($liq['lineas'] ?? []);

        // Pensión empleador 12% SIEMPRE — sobre IBC (salario mínimo SMMLV 1.423.500)
        $pension = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'EMP_PENSION');
        $this->assertNotNull($pension, 'BUG-013: falta línea EMP_PENSION (12%).');
        $this->assertEqualsWithDelta(
            1_500_000 * 0.12,
            (float) $pension['valor_total'],
            10,
            'EMP_PENSION = 12% × salario',
        );

        // ARL SIEMPRE
        $arl = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'EMP_ARL');
        $this->assertNotNull($arl, 'BUG-013: falta línea EMP_ARL.');

        // CCF SIEMPRE
        $ccf = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'EMP_CCF');
        $this->assertNotNull($ccf, 'BUG-013: falta línea EMP_CCF (4%).');
        $this->assertEqualsWithDelta(1_500_000 * 0.04, (float) $ccf['valor_total'], 10);

        // EXONERADOS — NO deben aparecer (omitidos por ser 0)
        foreach (['EMP_SALUD', 'EMP_SENA', 'EMP_ICBF'] as $exo) {
            $linea = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === $exo);
            $this->assertNull(
                $linea,
                "BUG-013: '{$exo}' debe estar EXONERADO (no aparecer) para salario < 10 SMMLV."
            );
        }
    }

    /**
     * BUG-013 (b): Empleado con salario > 10 SMMLV → NO aplica exoneración
     * Ley 1607: SALUD 8.5%, SENA 2%, ICBF 3% se calculan normalmente.
     */
    public function test_bug013_aportes_empleador_sin_exoneracion_si_salario_supera_10_smmlv(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        // 15.000.000 > 10 SMMLV (14.235.000) → NO aplica exoneración
        $this->crearContratoHttp($empleado->id, 15_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2027, 1);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $response->assertCreated();

        $liq = $response->json('data');
        $lineas = collect($liq['lineas'] ?? []);

        foreach (['EMP_SALUD', 'EMP_SENA', 'EMP_ICBF'] as $cod) {
            $linea = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === $cod);
            $this->assertNotNull(
                $linea,
                "BUG-013: '{$cod}' debe aparecer cuando salario > 10 SMMLV (sin exoneración).",
            );
            $this->assertGreaterThan(
                0,
                (float) $linea['valor_total'],
                "'{$cod}' debe tener valor > 0 sin exoneración.",
            );
        }

        // Tarifas esperadas (sobre IBC = 15M, no excede 25 SMMLV = 35.5M):
        $salud = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'EMP_SALUD');
        $this->assertEqualsWithDelta(15_000_000 * 0.085, (float) $salud['valor_total'], 100, 'SALUD 8.5%');

        $sena = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'EMP_SENA');
        $this->assertEqualsWithDelta(15_000_000 * 0.02, (float) $sena['valor_total'], 100, 'SENA 2%');

        $icbf = $lineas->first(fn ($l) => ($l['concepto']['codigo'] ?? '') === 'EMP_ICBF');
        $this->assertEqualsWithDelta(15_000_000 * 0.03, (float) $icbf['valor_total'], 100, 'ICBF 3%');
    }

    /**
     * BUG-013 (c): Las líneas de aporte_empleador NO deben afectar el
     * total_devengado ni el total_deduccion del empleado — son costo del
     * empleador y van como pasivos laborales en el asiento contable.
     */
    public function test_bug013_aportes_empleador_no_afectan_neto_del_empleado(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 2_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2027, 2);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"));
        $response->assertCreated();

        $liq = $response->json('data');

        // total_devengado debe ser solo salario + aux + extras (sin aportes empleador)
        // Con aux. transporte: 2.000.000 + 200.000 = 2.200.000
        $this->assertEqualsWithDelta(
            2_200_000,
            (float) $liq['total_devengado'],
            10,
            'total_devengado debe ser salario + aux (sin aportes empleador).',
        );

        // neto_pagar = devengado - deducciones (4%+4% sobre salario base, no aux)
        // IBC para empleado: 2.000.000 (salario), salud+pension = 8% × 2M = 160.000
        // Neto = 2.200.000 - 160.000 = 2.040.000
        $this->assertEqualsWithDelta(
            2_040_000,
            (float) $liq['neto_pagar'],
            10,
            'neto_pagar no debe restar aportes empleador.',
        );
    }

    // Regresión: el ruteo POST liquidaciones/{id}/aprobar no debe colisionar
    // con POST liquidaciones/{empleadoId}/{periodoId}.
    public function test_aprobar_liquidacion(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 2_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 7);

        $liq = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"))
            ->json('data');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'aprobado');
    }

    /**
     * BUG-014 (a): Al aprobar la liquidación se genera UN asiento contable
     * con partida doble que refleja gastos vs pasivos:
     *
     *   DB Sueldos + Aux + Provisiones + Aportes empleador
     *   CR Salarios por pagar (neto) + Seguridad social por pagar + Provisiones por pagar
     *
     * La columna `liquidaciones_nomina.asiento_id` queda poblada.
     */
    public function test_bug014_aprobar_genera_asiento_balanceado(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 1_500_000); // < 10 SMMLV → exoneración Ley 1607
        $periodo  = $this->crearPeriodoNominaHttp(2027, 3);

        $liq = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"))
            ->json('data');

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertOk();

        $liqModel = \App\Models\Tenant\LiquidacionNomina::findOrFail($liq['id']);
        $this->assertNotNull(
            $liqModel->asiento_id,
            'BUG-014: tras aprobar, liquidacion.asiento_id debe estar poblado.',
        );

        $asiento = \App\Models\Tenant\Asiento::with('lineas.cuenta')->findOrFail($liqModel->asiento_id);

        // Partida doble cuadra
        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals(
            $debitos,
            $creditos,
            "BUG-014: asiento de nómina desbalanceado. ∑D={$debitos}, ∑C={$creditos}.",
        );

        // Debe haber líneas DB de gasto y CR de pasivo
        $codigosDB = $asiento->lineas->where('debito', '>', 0)->pluck('cuenta.codigo')->toArray();
        $codigosCR = $asiento->lineas->where('credito', '>', 0)->pluck('cuenta.codigo')->toArray();

        $this->assertContains('510506', $codigosDB, 'Falta DB 510506 Sueldos.');
        $this->assertContains('250505', $codigosCR, 'Falta CR 250505 Salarios por pagar (neto).');
        $this->assertContains('237005', $codigosCR, 'Falta CR 237005 Aportes salud por pagar (al menos del empleado 4%).');
        $this->assertContains('237010', $codigosCR, 'Falta CR 237010 Aportes pensión por pagar.');
    }

    /**
     * BUG-014 (b): Aprobar dos veces no duplica el asiento — la segunda
     * aprobación retorna 409 (gestionado por aprobar()) y aunque no fuera
     * así, el ContabilizadorService::asientoExistenteDe garantiza
     * idempotencia a nivel polimorfico.
     */
    public function test_bug014_aprobacion_es_idempotente(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 2_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2027, 4);

        $liq = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"))
            ->json('data');

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertOk();

        // Solo UN asiento con este origen
        $count = \App\Models\Tenant\Asiento::query()
            ->where('origen_type', \App\Models\Tenant\LiquidacionNomina::class)
            ->where('origen_id', $liq['id'])
            ->count();
        $this->assertEquals(1, $count, 'BUG-014: debe existir UN solo asiento de nómina por liquidación.');
    }

    /**
     * BUG-014 (c): empleado con salario < 10 SMMLV (exoneración Ley 1607).
     * El asiento NO debe contener cuentas DB de salud empleador (510568),
     * SENA (510575) ni ICBF (510572). Estas exoneraciones reducen el costo
     * total de nómina para el empleador.
     */
    public function test_bug014_asiento_omite_aportes_exonerados_ley1607(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 1_500_000); // < 10 SMMLV
        $periodo  = $this->crearPeriodoNominaHttp(2027, 5);

        $liq = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"))
            ->json('data');

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertOk();

        $liqModel = \App\Models\Tenant\LiquidacionNomina::findOrFail($liq['id']);
        $asiento  = \App\Models\Tenant\Asiento::with('lineas.cuenta')->findOrFail($liqModel->asiento_id);

        $codigosDB = $asiento->lineas->where('debito', '>', 0)->pluck('cuenta.codigo')->toArray();

        foreach (['510568', '510575', '510572'] as $codigoExonerado) {
            $this->assertNotContains(
                $codigoExonerado,
                $codigosDB,
                "BUG-014: cuenta {$codigoExonerado} no debe aparecer (exoneración Ley 1607 salario < 10 SMMLV).",
            );
        }

        // Pensión empleador 510569 SIEMPRE (no exonerable)
        $this->assertContains(
            '510569',
            $codigosDB,
            'Pensión empleador (510569) siempre aplica, no exonerable.',
        );

        // ARL 510548 SIEMPRE
        $this->assertContains('510548', $codigosDB, 'ARL (510548) siempre aplica.');

        // CCF 510570 SIEMPRE
        $this->assertContains('510570', $codigosDB, 'Caja Compensación (510570) siempre aplica.');
    }

    public function test_aprobar_liquidacion_ya_aprobada_retorna_409(): void
    {
        $empleado = $this->crearEmpleadoHttp();
        $this->crearContratoHttp($empleado->id, 2_000_000);
        $periodo  = $this->crearPeriodoNominaHttp(2026, 8);

        $liq = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$empleado->id}/{$periodo->id}"))
            ->json('data');

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertOk();

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url("/liquidaciones/{$liq['id']}/aprobar"))
            ->assertStatus(409);
    }
}
