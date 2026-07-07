<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\CuentaSaldo;
use App\Models\Tenant\Impuesto;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use Tests\Concerns\ProvisionTestTenants;

/**
 * TestCase base para tests que requieren un contexto de tenant activo.
 *
 * Estrategia de aislamiento:
 *   - Si no existe ningún tenant, crea dos (test-empresa-a y test-empresa-b)
 *     con sus BDs, migrations y seeders ejecutados. Esta creación es persistente
 *     (no se hace rollback) y es idempotente en ejecuciones subsiguientes.
 *   - Envuelve cada test en una DB::transaction que se hace rollback en tearDown,
 *     garantizando que los datos del test no queden en la BD.
 *   - Las transacciones anidadas usan SAVEPOINT en PostgreSQL — compatible con
 *     los DB::transaction() internos de los Services.
 */
abstract class TenantTestCase extends TestCase
{
    use ProvisionTestTenants;

    protected \App\Models\Tenant $tenant;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTestTenantsExist();

        // Seleccionar EXPLÍCITAMENTE el tenant fixture A. Antes usaba
        // Tenant::query()->first() sin orden, lo que en presencia de tenants
        // reales (no fixture) podía elegir uno con datos pre-existentes que
        // colisionan con los inserts de los tests (e.g. periodos_contables_codigo_unique).
        $fixtureId = '99000000-0000-0000-0000-000000000001';
        /** @var \App\Models\Tenant|null $tenant */
        $tenant = \App\Models\Tenant::query()->find($fixtureId)
            ?? \App\Models\Tenant::query()->orderBy('id')->first();
        if ($tenant === null) {
            $this->markTestSkipped('No se pudo crear el tenant de prueba.');
        }
        $this->tenant = $tenant;

        tenancy()->initialize($this->tenant);

        // Sustituimos InitializeTenancyByTenantIdentifier con una versión que solo elimina el
        // parámetro {tenant} del route (como hace PathTenantResolver::forgetParameter)
        // sin reinicializar la tenancy ni purgar la conexión. Esto preserva la
        // transacción de BD abierta en setUp para aislamiento de tests.
        $this->app->instance(
            InitializeTenancyByTenantIdentifier::class,
            new class {
                public function handle(Request $request, \Closure $next): mixed
                {
                    $request->route()?->forgetParameter('tenant');
                    return $next($request);
                }
            }
        );

        /** @var User|null $user */
        $user = User::query()->where('role', User::ROLE_ADMIN)->first();
        if ($user === null) {
            $this->markTestSkipped('No hay usuario admin en el tenant. Verifica TenantAdminSeeder.');
        }
        $this->adminUser = $user;

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        tenancy()->end();
        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * Crea una cuenta contable de prueba (nivel subcuenta, acepta movimientos).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function crearCuenta(array $overrides = []): CuentaContable
    {
        $defaults = [
            'codigo'                => 'T' . substr(Str::uuid()->toString(), 0, 6),
            'nombre'                => 'Cuenta Test ' . Str::random(4),
            'naturaleza'            => 'debito',
            'nivel'                 => 'subcuenta',
            'clase'                 => 1,
            'acepta_movimientos'    => true,
            'exige_tercero'         => false,
            'exige_centro_costo'    => false,
            'exige_base_impuesto'   => false,
            'clasificacion_balance' => 'corriente',
            'clasificacion_pyg'     => 'na',
            'sistema'               => false,
            'editable'              => true,
            'activo'                => true,
        ];

        return CuentaContable::query()->create(array_merge($defaults, $overrides));
    }

    /**
     * Crea un periodo mensual de prueba.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function crearPeriodo(array $overrides = []): PeriodoContable
    {
        $anio  = $overrides['año_fiscal'] ?? 2026;
        $mes   = $overrides['mes']        ?? 1;
        $inicio = "{$anio}-" . str_pad((string) $mes, 2, '0', STR_PAD_LEFT) . '-01';
        $fin    = date('Y-m-t', strtotime($inicio));

        $defaults = [
            'tipo'        => PeriodoContable::TIPO_MENSUAL,
            'codigo'      => sprintf('%04d-%02d', $anio, $mes),
            'fecha_inicio' => $inicio,
            'fecha_fin'   => $fin,
            'año_fiscal'  => $anio,
            'mes'         => $mes,
            'estado'      => PeriodoContable::ESTADO_ABIERTO,
        ];

        return PeriodoContable::query()->create(array_merge($defaults, $overrides));
    }

    /**
     * Crea un asiento APROBADO sin disparar eventos del dominio.
     * Útil para poblar datos de test sin efectos secundarios en cuenta_saldos.
     *
     * @param  PeriodoContable                                               $periodo
     * @param  list<array{cuenta_id:string,debito:string,credito:string}>    $lineas
     * @param  array<string, mixed>                                          $overrides
     */
    protected function crearAsientoAprobado(
        PeriodoContable $periodo,
        array $lineas,
        array $overrides = [],
    ): Asiento {
        return Asiento::withoutEvents(function () use ($periodo, $lineas, $overrides): Asiento {
            $asiento = new Asiento();
            $asiento->forceFill(array_merge([
                'tipo_comprobante' => 'DB',
                'comprobante'      => 'Diario Básico',
                'numero_documento' => 'FIXTURE-001',
                'fecha'            => $periodo->fecha_inicio,
                'periodo_id'       => $periodo->id,
                'año_fiscal'       => $periodo->año_fiscal,
                'glosa'            => 'Asiento fixture test',
                'estado'           => Asiento::ESTADO_APROBADO,
                'created_by_id'    => $this->adminUser->id,
                'approved_by_id'   => $this->adminUser->id,
                'approved_at'      => now(),
            ], $overrides));
            $asiento->save();

            foreach ($lineas as $linea) {
                $lineaModel = new AsientoLinea();
                $lineaModel->forceFill([
                    'asiento_id'       => $asiento->id,
                    'cuenta_id'        => $linea['cuenta_id'],
                    'debito'           => $linea['debito']  ?? '0.0000',
                    'credito'          => $linea['credito'] ?? '0.0000',
                    'tercero_id'       => $linea['tercero_id'] ?? null,
                    'descripcion_item' => $linea['descripcion_item'] ?? 'Línea test',
                ]);
                $lineaModel->save();
            }

            return $asiento;
        });
    }

    /**
     * Crea un saldo materializado para una cuenta/periodo.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function crearSaldo(
        CuentaContable $cuenta,
        PeriodoContable $periodo,
        array $overrides = [],
    ): CuentaSaldo {
        $defaults = [
            'cuenta_contable_id'    => $cuenta->id,
            'periodo_id'            => $periodo->id,
            'tercero_id'            => null,
            'centro_costo_id'       => null,
            'sucursal_id'           => null,
            'saldo_inicial_debito'  => '0.0000',
            'saldo_inicial_credito' => '0.0000',
            'movimiento_debito'     => '0.0000',
            'movimiento_credito'    => '0.0000',
            'saldo_final_debito'    => '0.0000',
            'saldo_final_credito'   => '0.0000',
        ];

        return CuentaSaldo::query()->create(array_merge($defaults, $overrides));
    }

    /**
     * Crea un impuesto de prueba (no sistema) para tests de ImpuestoCalculador.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function crearImpuesto(string $cuentaId, array $overrides = []): Impuesto
    {
        $defaults = [
            'tipo'                    => 'retefuente',
            'codigo'                  => 'TEST-RF-' . Str::random(4),
            'nombre'                  => 'ReteFuente Test',
            'tarifa_porcentaje'       => '10.0000',
            'base_minima_uvt'         => null,
            'aplica_compras'          => true,
            'aplica_ventas'           => false,
            'cuenta_contable_id'      => $cuentaId,
            'cuenta_contrapartida_id' => null,
            'vigencia_desde'          => '2026-01-01',
            'vigencia_hasta'          => null,
            'activa'                  => true,
            'sistema'                 => false,
        ];

        return Impuesto::query()->create(array_merge($defaults, $overrides));
    }

    /**
     * Retorna la cuenta 2365 (ReteFuente) del PUC sembrado.
     */
    protected function cuenta2365(): CuentaContable
    {
        /** @var CuentaContable $cuenta */
        $cuenta = CuentaContable::query()->where('codigo', '2365')->firstOrFail();
        return $cuenta;
    }
}
