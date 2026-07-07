<?php

declare(strict_types=1);

namespace Tests\Feature\Impuesto;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Impuesto;
use App\Models\Tenant\ParametrizacionContable;
use Database\Seeders\TenantImpuestosSeeder;
use Database\Seeders\TenantParametrizacionSeeder;
use Database\Seeders\TenantPucSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

/**
 * Invariantes de integridad sobre el catálogo de impuestos y parametrización
 * contable relacionada con IVA.
 *
 * Antecedente: los Impuestos IVA-19/IVA-5/IVA-0 apuntaban a la cuenta padre
 * 2408 (acepta_movimientos=false), lo que rompería cualquier consumidor que
 * intentase postear un asiento usando ImpuestoCalculadorService.
 * Estos tests son guardrail para que no vuelva a ocurrir.
 *
 * Re-ejecuta los seeders al inicio para que los tenants provisionados
 * previamente reflejen el estado actual del código (la transacción del
 * TenantTestCase hace rollback al terminar).
 */
final class ImpuestosCuentasIntegridadTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PUC primero — el seeder de impuestos depende de subcuentas
        // (236505, 236605, 240805, 240810) que pueden no existir en tenants
        // provisionados antes de cambios al catálogo.
        Artisan::call('db:seed', [
            '--class' => TenantPucSeeder::class,
            '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => TenantImpuestosSeeder::class,
            '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => TenantParametrizacionSeeder::class,
            '--force' => true,
        ]);
    }

    public function test_toda_cuenta_de_impuesto_activo_acepta_movimientos(): void
    {
        $impuestos = Impuesto::query()->where('activa', true)->get();

        $this->assertNotEmpty($impuestos, 'No hay impuestos activos sembrados — TenantImpuestosSeeder no corrió.');

        foreach ($impuestos as $impuesto) {
            $cuenta = CuentaContable::query()->find($impuesto->cuenta_contable_id);
            $this->assertNotNull(
                $cuenta,
                "Impuesto {$impuesto->codigo}: cuenta_contable_id apunta a un UUID inexistente.",
            );
            $this->assertTrue(
                (bool) $cuenta->acepta_movimientos,
                "Impuesto {$impuesto->codigo}: cuenta {$cuenta->codigo} no acepta movimientos "
                . '(nivel ' . $cuenta->nivel . '). Los asientos posteados con este impuesto fallarían.',
            );

            if ($impuesto->cuenta_contrapartida_id !== null) {
                $contra = CuentaContable::query()->find($impuesto->cuenta_contrapartida_id);
                $this->assertNotNull($contra, "Impuesto {$impuesto->codigo}: contrapartida inexistente.");
                $this->assertTrue(
                    (bool) $contra->acepta_movimientos,
                    "Impuesto {$impuesto->codigo}: contrapartida {$contra->codigo} no acepta movimientos.",
                );
            }
        }
    }

    public function test_iva_ventas_y_compras_apuntan_a_cuentas_distintas(): void
    {
        $ventaIvaCuentaId = ParametrizacionContable::query()
            ->where('clave', 'venta.cuenta_iva_generado')
            ->value('cuenta_contable_id');
        $compraIvaCuentaId = ParametrizacionContable::query()
            ->where('clave', 'compra.cuenta_iva_descontable')
            ->value('cuenta_contable_id');

        $this->assertNotNull($ventaIvaCuentaId, 'Falta clave venta.cuenta_iva_generado en parametrización.');
        $this->assertNotNull($compraIvaCuentaId, 'Falta clave compra.cuenta_iva_descontable en parametrización.');
        $this->assertNotSame(
            $ventaIvaCuentaId,
            $compraIvaCuentaId,
            'IVA generado (ventas) y descontable (compras) deben apuntar a cuentas distintas '
            . 'para que la declaración bimestral (Formulario 300) sea trazable desde el mayor.',
        );

        $venta = CuentaContable::query()->findOrFail($ventaIvaCuentaId);
        $compra = CuentaContable::query()->findOrFail($compraIvaCuentaId);
        $this->assertSame('240805', $venta->codigo);
        $this->assertSame('240810', $compra->codigo);
    }

    public function test_catalogo_iva_cubre_tarifas_dian_2026(): void
    {
        $codigosEsperados = ['IVA-19', 'IVA-5', 'IVA-0', 'IVA-EXCLUIDO'];

        $codigosSembrados = Impuesto::query()
            ->where('tipo', 'iva')
            ->where('sistema', true)
            ->where('activa', true)
            ->pluck('codigo')
            ->all();

        foreach ($codigosEsperados as $codigo) {
            $this->assertContains(
                $codigo,
                $codigosSembrados,
                "Falta el impuesto sistema '{$codigo}' en el catálogo de IVA.",
            );
        }
    }

    public function test_iva_que_aplica_compras_tiene_cuenta_contrapartida(): void
    {
        $impuestosIvaCompras = Impuesto::query()
            ->where('tipo', 'iva')
            ->where('sistema', true)
            ->where('aplica_compras', true)
            ->where('aplica_ventas', true)
            ->where('tarifa_porcentaje', '>', 0)
            ->get();

        $this->assertNotEmpty($impuestosIvaCompras);

        foreach ($impuestosIvaCompras as $impuesto) {
            $this->assertNotNull(
                $impuesto->cuenta_contrapartida_id,
                "Impuesto {$impuesto->codigo} aplica a compras Y ventas con tarifa > 0; "
                . 'requiere cuenta_contrapartida_id apuntando a la subcuenta de IVA descontable (240810).',
            );

            $contra = CuentaContable::query()->findOrFail($impuesto->cuenta_contrapartida_id);
            $this->assertSame(
                '240810',
                $contra->codigo,
                "Impuesto {$impuesto->codigo}: contrapartida debe ser 240810 (IVA descontable).",
            );
        }
    }
}
