<?php

declare(strict_types=1);

namespace Tests\Feature\Impuesto;

use App\Services\Impuesto\ImpuestoCalculadorService;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * ImpuestoCalculadorService — base mínima UVT, vigencias, códigos DIAN.
 *
 * Usa los impuestos sembrados por TenantImpuestosSeeder (IVA-19, RF-HONORARIOS-10…).
 * Los test fixtures adicionales se crean dentro de la transacción y se descartan.
 */
final class ImpuestoCalculadorServiceTest extends TenantTestCase
{
    private ImpuestoCalculadorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ImpuestoCalculadorService::class);
    }

    // ── IVA ──────────────────────────────────────────────────────────────────

    public function test_iva_19_calcula_correctamente(): void
    {
        $resultado = $this->service->calcular(
            base:           '1000000',
            codigoImpuesto: 'IVA-19',
        );

        $this->assertSame('IVA-19', $resultado->codigo);
        $this->assertSame('iva', $resultado->tipo);
        $this->assertSame('190000.0000', $resultado->impuestoCalculado);
        $this->assertFalse($resultado->baseBajoUmbral);
        $this->assertNull($resultado->baseMinimaUvt);
    }

    public function test_iva_5_calcula_correctamente(): void
    {
        $resultado = $this->service->calcular(
            base:           '2000000',
            codigoImpuesto: 'IVA-5',
        );

        $this->assertSame('100000.0000', $resultado->impuestoCalculado);
    }

    public function test_iva_0_devuelve_cero(): void
    {
        $resultado = $this->service->calcular(
            base:           '5000000',
            codigoImpuesto: 'IVA-0',
        );

        $this->assertSame('0.0000', $resultado->impuestoCalculado);
    }

    // ── ReteFuente ────────────────────────────────────────────────────────────

    public function test_retefuente_honorarios_10_sobre_base_simple(): void
    {
        $resultado = $this->service->calcular(
            base:           '5000000',
            codigoImpuesto: 'RF-HONORARIOS-10',
        );

        $this->assertSame('retefuente', $resultado->tipo);
        $this->assertSame('500000.0000', $resultado->impuestoCalculado);
        $this->assertFalse($resultado->baseBajoUmbral);
    }

    public function test_retefuente_compras_con_base_minima_uvt_bajo_umbral(): void
    {
        // RF-COMPRAS-35 tiene base_minima_uvt=27 UVT. UVT 2026 = 49.799 COP
        // 27 * 49.799 = 1.344.573. Si la base es 1.000.000 → bajo umbral → impuesto = 0
        $resultado = $this->service->calcular(
            base:           '1000000',
            codigoImpuesto: 'RF-COMPRAS-35',
            fecha:          new \DateTimeImmutable('2026-06-15'),
        );

        $this->assertTrue($resultado->baseBajoUmbral);
        $this->assertSame('0.0000', $resultado->impuestoCalculado);
        $this->assertNotNull($resultado->baseMinimaUvt);
        $this->assertNotNull($resultado->baseMinimaAplicadaCop);
    }

    public function test_retefuente_compras_sobre_umbral_calcula(): void
    {
        // Base 5.000.000 >> 27 UVT → debe calcular 3.5%
        $resultado = $this->service->calcular(
            base:           '5000000',
            codigoImpuesto: 'RF-COMPRAS-35',
            fecha:          new \DateTimeImmutable('2026-06-15'),
        );

        $this->assertFalse($resultado->baseBajoUmbral);
        $this->assertSame('175000.0000', $resultado->impuestoCalculado);
    }

    // ── Vigencias ────────────────────────────────────────────────────────────

    public function test_impuesto_expirado_lanza_excepcion(): void
    {
        $cuenta  = $this->cuenta2365();
        $codigo  = 'TEST-VENCIDO-' . uniqid();
        $this->crearImpuesto($cuenta->id, [
            'codigo'         => $codigo,
            'vigencia_desde' => '2020-01-01',
            'vigencia_hasta' => '2021-12-31',
            'activa'         => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no encontrado o no vigente/i');

        $this->service->calcular(
            base:           '1000000',
            codigoImpuesto: $codigo,
            fecha:          new \DateTimeImmutable('2026-01-01'),
        );
    }

    public function test_codigo_inexistente_lanza_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no encontrado/');

        $this->service->calcular(
            base:           '1000000',
            codigoImpuesto: 'CODIGO-QUE-NO-EXISTE-XYZ',
        );
    }

    public function test_impuesto_inactivo_lanza_excepcion(): void
    {
        $cuenta = $this->cuenta2365();
        $codigo = 'TEST-INACTIVO-' . \Illuminate\Support\Str::random(4);
        $this->crearImpuesto($cuenta->id, [
            'codigo'  => $codigo,
            'activa'  => false,
        ]);

        $this->expectException(RuntimeException::class);

        $this->service->calcular(
            base:           '1000000',
            codigoImpuesto: $codigo,
        );
    }

    // ── calcularMultiples ─────────────────────────────────────────────────────

    public function test_calcular_multiples_suma_correctamente(): void
    {
        $resultado = $this->service->calcularMultiples(
            base:   '1000000',
            codigos: ['IVA-19', 'RF-HONORARIOS-10'],
        );

        // IVA 19% = 190.000 + RF 10% = 100.000 → total = 290.000
        $this->assertSame('290000.0000', $resultado['total']);
        $this->assertCount(2, $resultado['items']);
    }
}
