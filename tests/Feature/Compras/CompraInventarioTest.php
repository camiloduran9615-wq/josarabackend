<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\Tenant\Bodega;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\InventarioMovimiento;
use App\Models\Tenant\ParametrizacionContable;
use App\Models\Tenant\Producto;
use App\Models\Tenant\Sucursal;
use App\Models\Tenant\Tercero;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Tests de integración: Flujo Compra → Inventario → Asiento Contable.
 *
 * Estos tests verifican que nuestra plataforma replica el comportamiento
 * de SIIGO Nube para el documento "Factura de Compra" (Tipo P):
 *
 *  ✅ SC-001  Factura de compra a crédito con IVA 19% → inventario sube + asiento cuadrado
 *  ✅ SC-002  Compra de contado → cuenta caja como crédito
 *  ✅ SC-003  Múltiples ítems con distintas tasas de IVA
 *  ✅ SC-004  Ítem sin producto_id (gasto/servicio) → no mueve inventario
 *  ✅ SC-005  Con retenciones (RetefuentE + ReteICA)
 *  ⏳ SC-006  Idempotencia → pendiente implementación de deduplicación por número_doc_proveedor
 *  ✅ SC-007  Periodo cerrado → rechaza la compra (HTTP 422)
 *  ✅ SC-008  Anulación → reversa el stock
 *  ✅ SC-009  Proveedor inexistente → validación HTTP 422
 *  ✅ SC-010  Cantidad cero → validación HTTP 422
 *  ✅ SC-011  Stock nunca queda negativo por error de anulación doble
 *  ✅ SC-012  El asiento generado suma débitos == créditos (cuadre contable)
 */
class CompraInventarioTest extends TenantTestCase
{
    // ─── Fixtures ────────────────────────────────────────────────────────────

    private User $contador;
    private Tercero $proveedor;
    private Producto $producto;
    private Bodega $bodega;
    private string $cuentaGastoId;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function tenantUrl(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    /**
     * Crea el entorno mínimo para ejecutar los tests de compras.
     */
    private function seedFixtures(): void
    {
        // Usuario contador autenticado
        $this->contador = User::create([
            'nombre'   => 'Contador',
            'apellido' => 'Compras',
            'email'    => 'contador-compras-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Periodo contable abierto usando el helper de TenantTestCase
        $this->crearPeriodo([
            'año_fiscal' => (int) date('Y'),
            'mes'        => (int) date('m'),
        ]);

        // Tercero proveedor
        $this->proveedor = Tercero::create([
            'tipo_persona'              => 'juridica',
            'tipo_documento'            => 'nit',
            'numero_documento'          => '900123456',
            'identificacion_documento_id' => '31',   // NIT
            'identificacion'            => '900123456-' . rand(0, 9),
            'razon_social'              => 'Proveedor Test S.A.S.',
            'email'                     => 'prov@test.co',
            'es_proveedor'              => true,
            'activo'                    => true,
        ]);

        // Cuentas contables mínimas para parametrización
        $this->seedCuentasYParametrizacion();

        // Bodega: reutilizar la existente del seeder, o crear una con la sucursal del tenant
        $bodegaExistente = Bodega::first();
        if ($bodegaExistente !== null) {
            $this->bodega = $bodegaExistente;
        } else {
            $sucursal = Sucursal::first();
            if ($sucursal === null) {
                $this->markTestSkipped('No hay sucursal en el tenant de prueba para crear Bodega.');
            }
            $this->bodega = Bodega::create([
                'sucursal_id' => $sucursal->id,
                'codigo'      => 'BOD-TEST-' . Str::random(4),
                'nombre'      => 'Bodega Test Compras',
                'tipo'        => 'mercancia',
            ]);
        }

        // Cuenta gasto para ítems sin producto (tipo_linea='gasto')
        /** @var string $cuentaGastoId */
        $cuentaGastoId = CuentaContable::where('codigo', '143005')->value('id')
            ?? CuentaContable::where('acepta_movimientos', true)->value('id');
        $this->cuentaGastoId = (string) $cuentaGastoId;

        // Producto de catálogo — stock_actual=0 porque CostoPromedioService
        // lo recalcula como SUM(producto_stock_bodega.saldo_unidades), ignorando
        // el valor inicial que se pusiera aquí.
        $this->producto = Producto::create([
            'codigo'        => 'PROD-001-' . Str::random(4),
            'nombre'        => 'Producto de Prueba',
            'unidad_medida' => '94',
            'precio_venta'  => 100000,
            'precio_compra' => 60000,
            'stock_actual'  => 0,
            'stock_minimo'  => 2,
            'porcentaje_iva'=> 19,
            'activo'        => true,
        ]);
    }

    // ─── SC-001: Compra a crédito con IVA ────────────────────────────────────

    /**
     * SC-001: Factura de compra a crédito con IVA 19%.
     *
     * Producto: 5 unidades × $60.000 = $300.000 neto
     * IVA 19% = $57.000
     * Total a pagar al proveedor = $357.000
     *
     * Asiento esperado:
     *  DÉBITO  143005  $300.000  (Inventario Mercancías)
     *  DÉBITO  240810  $57.000   (IVA Descontable)
     *  CRÉDITO 220505  $357.000  (Proveedores Nacionales)
     *
     * Inventario: stock sube de 10 → 15 unidades
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc001_compra_credito_iva_19_actualiza_inventario_y_genera_asiento(): void
    {
        $this->seedFixtures();

        $payload = [
            'tercero_id'  => $this->proveedor->id,
            'tipo'        => 'factura_compra',
            'fecha'       => now()->toDateString(),
            'concepto'    => 'Compra de mercancía para reventa',
            'forma_pago'  => 'credito',
            'items'       => [
                [
                    'tipo_linea'     => 'producto',
                    'producto_id'    => $this->producto->id,
                    'bodega_id'      => $this->bodega->id,
                    'descripcion'    => 'Producto de Prueba',
                    'cantidad'       => 5,
                    'precio_unitario'=> 60000,
                    'porcentaje_iva' => 19,
                ],
            ],
            'numero_documento_proveedor' => 'FAC-PROV-001',
        ];

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.tipo', 'factura_compra')
                 ->assertJsonPath('data.estado', 'registrado');

        $docId = $response->json('data.id');

        // ── Verificar totales del documento ──
        $doc = DocumentoIngreso::findOrFail($docId);
        $this->assertEquals(300000.00, (float) $doc->valor_bruto,   'Valor bruto debe ser $300.000');
        $this->assertEquals(57000.00,  (float) $doc->valor_iva,     'IVA debe ser $57.000 (19%)');
        $this->assertEquals(357000.00, (float) $doc->valor_total,   'Total debe ser $357.000');

        // ── Verificar movimiento de inventario ──
        $movimiento = InventarioMovimiento::where('documento_ingreso_id', $docId)->first();
        $this->assertNotNull($movimiento, 'Debe crearse un movimiento de inventario');
        $this->assertEquals('entrada_compra', $movimiento->tipo);
        $this->assertEquals(5.0,              (float) $movimiento->cantidad);
        $this->assertEquals(60000.0,          (float) $movimiento->costo_unitario);

        // ── Verificar stock actualizado ──
        // CostoPromedioService recalcula stock_actual = SUM(producto_stock_bodega).
        // El producto empieza en 0 y sube a 5 después de la compra.
        $this->producto->refresh();
        $this->assertEquals(5.0, (float) $this->producto->stock_actual,
            'Stock debe pasar de 0 a 5 unidades');

        // ── Verificar asiento contable ──
        $this->assertNotNull($doc->asiento_id, 'El documento debe tener un asiento generado');
        $asiento = $doc->asiento()->with('lineas.cuenta')->first();
        $this->assertNotNull($asiento);

        $debitos  = $asiento->lineas->sum('debito');
        $creditos = $asiento->lineas->sum('credito');
        $this->assertEquals(round($debitos, 2), round($creditos, 2),
            "El asiento debe cuadrar: débitos={$debitos} créditos={$creditos}");

        // Verificar las cuentas específicas
        $codigosDebito  = $asiento->lineas->where('debito', '>', 0)->pluck('cuenta.codigo')->toArray();
        $codigosCredito = $asiento->lineas->where('credito', '>', 0)->pluck('cuenta.codigo')->toArray();

        $this->assertContains('143005', $codigosDebito,  'Debe debitar Inventario de Mercancías (143005)');
        $this->assertContains('240810', $codigosDebito,  'Debe debitar IVA Descontable (240810)');
        $this->assertContains('220505', $codigosCredito, 'Debe acreditar Proveedores (220505)');

        // Verificar montos en asiento
        $lineaInventario = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '143005');
        $lineaIva        = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '240810');
        $lineaProveedor  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '220505');

        $this->assertEquals(300000.0, (float) $lineaInventario->debito, 'Débito inventario debe ser $300.000');
        $this->assertEquals(57000.0,  (float) $lineaIva->debito,        'Débito IVA debe ser $57.000');
        $this->assertEquals(357000.0, (float) $lineaProveedor->credito, 'Crédito proveedor debe ser $357.000');
    }

    // ─── SC-002: Compra de contado ────────────────────────────────────────────

    /**
     * SC-002: Compra de contado → crédito a Caja (110505), no a Proveedores.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc002_compra_contado_acredita_caja(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Compra de contado',
                'forma_pago' => 'contado',
                'items'      => [[
                    'tipo_linea'     => 'producto',
                    'producto_id'    => $this->producto->id,
                    'bodega_id'      => $this->bodega->id,
                    'descripcion'    => 'Producto de Prueba',
                    'cantidad'       => 2,
                    'precio_unitario'=> 60000,
                    'porcentaje_iva' => 0,
                ]],
            ]);

        $response->assertStatus(201);
        $doc     = DocumentoIngreso::findOrFail($response->json('data.id'));
        $asiento = $doc->asiento()->with('lineas.cuenta')->first();

        $codigosCredito = $asiento->lineas->where('credito', '>', 0)->pluck('cuenta.codigo')->toArray();

        $this->assertContains('110505', $codigosCredito,
            'Compra de contado debe acreditar Caja (110505)');
        $this->assertNotContains('220505', $codigosCredito,
            'Compra de contado NO debe acreditar Proveedores (220505)');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function compra_contado_efectivo_es_aceptada_por_la_base_de_datos(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo' => 'factura_compra',
                'fecha' => now()->toDateString(),
                'concepto' => 'Compra de contado en efectivo',
                'forma_pago' => 'contado_efectivo',
                'items' => [[
                    'tipo_linea' => 'producto',
                    'producto_id' => $this->producto->id,
                    'bodega_id' => $this->bodega->id,
                    'descripcion' => 'Producto de Prueba',
                    'cantidad' => 1,
                    'precio_unitario' => 60000,
                    'porcentaje_iva' => 0,
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.forma_pago', 'contado_efectivo');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function compra_contado_banco_es_aceptada_por_la_base_de_datos(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo' => 'factura_compra',
                'fecha' => now()->toDateString(),
                'concepto' => 'Compra de contado por banco',
                'forma_pago' => 'contado_banco',
                'items' => [[
                    'tipo_linea' => 'producto',
                    'producto_id' => $this->producto->id,
                    'bodega_id' => $this->bodega->id,
                    'descripcion' => 'Producto de Prueba',
                    'cantidad' => 1,
                    'precio_unitario' => 60000,
                    'porcentaje_iva' => 0,
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.forma_pago', 'contado_banco');
    }

    // ─── SC-003: Múltiples ítems con distintas tasas de IVA ──────────────────

    /**
     * SC-003: 2 ítems — uno con IVA 19%, otro exento (IVA 0%).
     *
     * Ítem A: 3 × $50.000 = $150.000 + IVA $28.500  → subtotal $178.500
     * Ítem B: 2 × $30.000 = $60.000  + IVA $0       → subtotal $60.000
     * Total = $238.500
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc003_multiples_items_distintas_tasas_iva(): void
    {
        $this->seedFixtures();

        $productoExento = Producto::create([
            'codigo'        => 'PROD-002-' . Str::random(4),
            'nombre'        => 'Producto Exento',
            'unidad_medida' => '94',
            'precio_venta'  => 30000,
            'precio_compra' => 30000,
            'stock_actual'  => 0,
            'porcentaje_iva'=> 0,
            'activo'        => true,
        ]);

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Compra mixta',
                'forma_pago' => 'credito',
                'items'      => [
                    [
                        'tipo_linea'     => 'producto',
                        'producto_id'    => $this->producto->id,
                        'bodega_id'      => $this->bodega->id,
                        'descripcion'    => 'Producto con IVA',
                        'cantidad'       => 3,
                        'precio_unitario'=> 50000,
                        'porcentaje_iva' => 19,
                    ],
                    [
                        'tipo_linea'     => 'producto',
                        'producto_id'    => $productoExento->id,
                        'bodega_id'      => $this->bodega->id,
                        'descripcion'    => 'Producto Exento',
                        'cantidad'       => 2,
                        'precio_unitario'=> 30000,
                        'porcentaje_iva' => 0,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $doc = DocumentoIngreso::findOrFail($response->json('data.id'));

        $this->assertEquals(210000.00, (float) $doc->valor_bruto, 'Bruto = $150.000 + $60.000');
        $this->assertEquals(28500.00,  (float) $doc->valor_iva,   'IVA = $28.500 (solo ítem A)');
        $this->assertEquals(238500.00, (float) $doc->valor_total, 'Total = $238.500');

        // Ambos productos deben tener su movimiento de inventario
        $movimientos = InventarioMovimiento::where('documento_ingreso_id', $doc->id)->get();
        $this->assertCount(2, $movimientos, 'Debe haber 2 movimientos de inventario');

        // Stocks actualizados (CPP recalcula stock_actual desde producto_stock_bodega)
        $this->producto->refresh();
        $productoExento->refresh();
        $this->assertEquals(3.0, (float) $this->producto->stock_actual,  'Stock PROD-001: 0+3=3');
        $this->assertEquals(2.0, (float) $productoExento->stock_actual,  'Stock PROD-002: 0+2=2');

        // Solo debe haber UNA línea de IVA descontable (del ítem gravado)
        $asiento   = $doc->asiento()->with('lineas.cuenta')->first();
        $lineasIva = $asiento->lineas->filter(fn ($l) => $l->cuenta->codigo === '240810');
        $this->assertEquals(28500.0, (float) $lineasIva->sum('debito'),
            'El total de IVA descontable en el asiento debe ser $28.500');
    }

    // ─── SC-004: Ítem de gasto/servicio sin producto_id ──────────────────────

    /**
     * SC-004: Un ítem sin producto_id (gasto de transporte, p.ej.) no debe
     * crear movimiento de inventario ni tocar stock.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc004_item_sin_producto_no_mueve_inventario(): void
    {
        $this->seedFixtures();
        $stockAntes = (float) $this->producto->stock_actual;

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Servicio de transporte',
                'forma_pago' => 'credito',
                'items'      => [[
                    // Sin producto_id — es un gasto/servicio
                    'tipo_linea'     => 'gasto',
                    'cuenta_id'      => $this->cuentaGastoId,
                    'descripcion'    => 'Flete de importación',
                    'cantidad'       => 1,
                    'precio_unitario'=> 150000,
                    'porcentaje_iva' => 19,
                ]],
            ]);

        $response->assertStatus(201);
        $docId = $response->json('data.id');

        $movimientos = InventarioMovimiento::where('documento_ingreso_id', $docId)->get();
        $this->assertCount(0, $movimientos, 'No debe crearse movimiento de inventario para gasto/servicio');

        $this->producto->refresh();
        $this->assertEquals($stockAntes, (float) $this->producto->stock_actual,
            'El stock no debe cambiar cuando no hay producto_id');
    }

    // ─── SC-005: Con retenciones ──────────────────────────────────────────────

    /**
     * SC-005: Compra con RetefuentE 2.5% y ReteICA 0.414%.
     *
     * Base: $200.000  IVA: $38.000  Total bruto: $238.000
     * RetefuentE: $5.000 (2.5% sobre base)
     * ReteICA:    $828   (0.414% sobre base)
     * Total a pagar: $238.000 - $5.000 - $828 = $232.172
     *
     * Asiento:
     *  DÉBITO  143005  $200.000
     *  DÉBITO  240810  $38.000
     *  CRÉDITO 220505  $232.172  (lo que realmente se paga)
     *  CRÉDITO 236540  $5.000    (retefuente retenida)
     *  CRÉDITO 236801  $828      (reteica retenida)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc005_compra_con_retenciones_genera_lineas_correctas(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id'      => $this->proveedor->id,
                'tipo'            => 'factura_compra',
                'fecha'           => now()->toDateString(),
                'concepto'        => 'Compra con retenciones',
                'forma_pago'      => 'credito',
                'valor_retefuente'=> 5000,
                'valor_reteica'   => 828,
                'items'           => [[
                    'tipo_linea'     => 'producto',
                    'producto_id'    => $this->producto->id,
                    'bodega_id'      => $this->bodega->id,
                    'descripcion'    => 'Producto',
                    'cantidad'       => 1,
                    'precio_unitario'=> 200000,
                    'porcentaje_iva' => 19,
                ]],
            ]);

        $response->assertStatus(201);
        $doc = DocumentoIngreso::findOrFail($response->json('data.id'));

        $this->assertEquals(200000.0, (float) $doc->valor_bruto);
        $this->assertEquals(38000.0,  (float) $doc->valor_iva);
        $this->assertEquals(5000.0,   (float) $doc->valor_retefuente);
        $this->assertEquals(828.0,    (float) $doc->valor_reteica);
        // Total = 200000 + 38000 - 5000 - 828 = 232172
        $this->assertEquals(232172.0, (float) $doc->valor_total);

        $asiento = $doc->asiento()->with('lineas.cuenta')->first();
        $codigos  = $asiento->lineas->pluck('cuenta.codigo')->toArray();

        $this->assertContains('236540', $codigos, 'Debe haber línea de RetefuentE (236540)');
        $this->assertContains('236801', $codigos, 'Debe haber línea de ReteICA (236801)');

        $lineaRetef  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '236540');
        $lineaReteica = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '236801');

        $this->assertEquals(5000.0, (float) $lineaRetef->credito);
        $this->assertEquals(828.0,  (float) $lineaReteica->credito);

        // El asiento debe cuadrar
        $debitos  = round($asiento->lineas->sum('debito'), 2);
        $creditos = round($asiento->lineas->sum('credito'), 2);
        $this->assertEquals($debitos, $creditos, "Asiento no cuadra: D={$debitos} C={$creditos}");
    }

    // ─── SC-006: Idempotencia ─────────────────────────────────────────────────

    /**
     * SC-006: Doble submit con el mismo numero_documento_proveedor debe rechazarse en 422.
     * El stock y los asientos solo deben crearse una vez.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc006_doble_submit_no_duplica_asiento_ni_stock(): void
    {
        $this->seedFixtures();

        $payload = [
            'tercero_id'                 => $this->proveedor->id,
            'tipo'                       => 'factura_compra',
            'fecha'                      => now()->toDateString(),
            'concepto'                   => 'Compra idempotencia SC-006',
            'forma_pago'                 => 'credito',
            'numero_documento_proveedor' => 'FAC-PROV-SC006',
            'items'                      => [[
                'tipo_linea'     => 'producto',
                'producto_id'    => $this->producto->id,
                'bodega_id'      => $this->bodega->id,
                'descripcion'    => 'Producto idempotencia',
                'cantidad'       => 3,
                'precio_unitario'=> 40000,
                'porcentaje_iva' => 19,
            ]],
        ];

        // Primer envío → crea el documento
        $r1 = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), $payload);
        $r1->assertStatus(201);
        $docId = $r1->json('data.id');
        $this->assertNotNull($docId);

        // Segundo envío idéntico → rechazado 422
        $r2 = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), $payload);
        $r2->assertStatus(422)->assertJsonPath('success', false);

        // Solo un documento con ese número de proveedor
        $this->assertEquals(1,
            DocumentoIngreso::where('tercero_id', $this->proveedor->id)
                ->where('numero_documento_proveedor', 'FAC-PROV-SC006')
                ->count(),
            'El doble submit no debe duplicar el documento'
        );

        // Stock solo subió por el primer envío (3 unidades)
        $this->producto->refresh();
        $this->assertEquals(3.0, (float) $this->producto->stock_actual,
            'El stock solo debe reflejar la primera compra'
        );
    }

    // ─── SC-007: Periodo cerrado ──────────────────────────────────────────────

    /**
     * SC-007: No se puede crear una compra si el periodo contable está cerrado.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc007_periodo_cerrado_rechaza_la_compra(): void
    {
        $this->seedFixtures();

        // Cerrar el periodo del mes actual
        \App\Models\Tenant\PeriodoContable::query()
            ->where('año_fiscal', (int) date('Y'))
            ->where('mes', (int) date('m'))
            ->update(['estado' => 'cerrado']);

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Intento en periodo cerrado',
                'forma_pago' => 'credito',
                'items'      => [[
                    'tipo_linea'     => 'gasto',
                    'cuenta_id'      => $this->cuentaGastoId,
                    'descripcion'    => 'Producto',
                    'cantidad'       => 1,
                    'precio_unitario'=> 10000,
                    'porcentaje_iva' => 0,
                ]],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'periodo',
            strtolower($response->json('message') ?? ''),
            'El mensaje de error debe mencionar el periodo'
        );
    }

    // ─── SC-008: Anulación reversa el stock ───────────────────────────────────

    /**
     * SC-008: Al anular una factura de compra, el stock debe volver al valor anterior.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc008_anulacion_reversa_el_stock(): void
    {
        $this->seedFixtures();
        $stockOriginal = (float) $this->producto->stock_actual; // 10

        // Registrar compra
        $create = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Compra a anular',
                'forma_pago' => 'credito',
                'items'      => [[
                    'tipo_linea'     => 'producto',
                    'producto_id'    => $this->producto->id,
                    'bodega_id'      => $this->bodega->id,
                    'descripcion'    => 'Producto',
                    'cantidad'       => 4,
                    'precio_unitario'=> 60000,
                    'porcentaje_iva' => 19,
                ]],
            ]);
        $create->assertStatus(201);
        $docId = $create->json('data.id');

        $this->producto->refresh();
        $this->assertEquals($stockOriginal + 4, (float) $this->producto->stock_actual,
            'Stock debe ser ' . ($stockOriginal + 4) . ' después de la compra');

        // Anular
        $delete = $this->actingAs($this->contador, 'sanctum')
            ->deleteJson($this->tenantUrl("/documentos-ingreso/{$docId}"));
        $delete->assertStatus(200)->assertJsonPath('success', true);

        $this->producto->refresh();
        $this->assertEquals($stockOriginal, (float) $this->producto->stock_actual,
            "Stock debe volver a {$stockOriginal} después de la anulación");

        // El documento debe quedar en estado anulado (soft deleted)
        $this->assertSoftDeleted('documentos_ingreso', ['id' => $docId]);
    }

    // ─── SC-009: Validaciones de negocio ─────────────────────────────────────

    /**
     * SC-009: Proveedor inexistente → HTTP 422.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc009_proveedor_inexistente_retorna_422(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => Str::uuid(), // UUID que no existe
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Test validación',
                'forma_pago' => 'credito',
                'items'      => [[
                    'descripcion'    => 'Algo',
                    'cantidad'       => 1,
                    'precio_unitario'=> 10000,
                ]],
            ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['tercero_id']);
    }

    /**
     * SC-010: Cantidad cero → HTTP 422.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc010_cantidad_cero_retorna_422(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Test cantidad cero',
                'forma_pago' => 'credito',
                'items'      => [[
                    'producto_id'    => $this->producto->id,
                    'descripcion'    => 'Producto',
                    'cantidad'       => 0,  // ← inválido
                    'precio_unitario'=> 60000,
                ]],
            ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['items.0.cantidad']);
    }

    // ─── SC-011: No stock negativo por anulación doble ────────────────────────

    /**
     * SC-011: Anular dos veces el mismo documento no deja el stock negativo.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc011_anulacion_doble_no_deja_stock_negativo(): void
    {
        $this->seedFixtures();

        $create = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id' => $this->proveedor->id,
                'tipo'       => 'factura_compra',
                'fecha'      => now()->toDateString(),
                'concepto'   => 'Compra doble anulación',
                'forma_pago' => 'credito',
                'items'      => [[
                    'tipo_linea'     => 'producto',
                    'producto_id'    => $this->producto->id,
                    'bodega_id'      => $this->bodega->id,
                    'descripcion'    => 'Producto',
                    'cantidad'       => 3,
                    'precio_unitario'=> 60000,
                    'porcentaje_iva' => 0,
                ]],
            ]);
        $docId = $create->json('data.id');

        // Primera anulación → éxito
        $this->actingAs($this->contador, 'sanctum')
            ->deleteJson($this->tenantUrl("/documentos-ingreso/{$docId}"))
            ->assertStatus(200);

        // Segunda anulación → debe rechazarse (ya anulado)
        $second = $this->actingAs($this->contador, 'sanctum')
            ->deleteJson($this->tenantUrl("/documentos-ingreso/{$docId}"));
        $second->assertStatus(422);

        // Stock no debe quedar negativo
        $this->producto->refresh();
        $this->assertGreaterThanOrEqual(0, (float) $this->producto->stock_actual,
            'El stock nunca debe quedar negativo');
    }

    // ─── SC-012: Cuadre contable ──────────────────────────────────────────────

    /**
     * SC-012: El asiento generado siempre debe cuadrar (Σdébitos = Σcréditos).
     * Caso con números con decimales complejos.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sc012_asiento_siempre_cuadra_con_decimales_complejos(): void
    {
        $this->seedFixtures();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/documentos-ingreso'), [
                'tercero_id'      => $this->proveedor->id,
                'tipo'            => 'factura_compra',
                'fecha'           => now()->toDateString(),
                'concepto'        => 'Compra cuadre decimal',
                'forma_pago'      => 'credito',
                'valor_retefuente'=> 1333.33,
                'items'           => [
                    [
                        'tipo_linea'     => 'producto',
                        'producto_id'    => $this->producto->id,
                        'bodega_id'      => $this->bodega->id,
                        'descripcion'    => 'Producto A',
                        'cantidad'       => 7,
                        'precio_unitario'=> 33333.33,
                        'porcentaje_iva' => 19,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $doc     = DocumentoIngreso::findOrFail($response->json('data.id'));
        $asiento = $doc->asiento()->with('lineas')->first();

        $debitos  = round($asiento->lineas->sum('debito'), 2);
        $creditos = round($asiento->lineas->sum('credito'), 2);

        $this->assertEquals($debitos, $creditos,
            "SC-012 FALLA: Asiento no cuadra con decimales. D={$debitos} C={$creditos}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea el PUC mínimo y la parametrización contable para compras.
     * Usa firstOrCreate para ser idempotente si TenantPucSeeder ya sembró las cuentas.
     */
    private function seedCuentasYParametrizacion(): void
    {
        $cuentas = [
            ['codigo' => '11',     'nombre' => 'Disponible',                  'nivel' => 'clase',    'parent_codigo' => null,   'naturaleza' => 'debito'],
            ['codigo' => '14',     'nombre' => 'Inventarios',                 'nivel' => 'clase',    'parent_codigo' => null,   'naturaleza' => 'debito'],
            ['codigo' => '22',     'nombre' => 'Cuentas por Pagar',           'nivel' => 'clase',    'parent_codigo' => null,   'naturaleza' => 'credito'],
            ['codigo' => '23',     'nombre' => 'Impuestos Gravámenes',        'nivel' => 'clase',    'parent_codigo' => null,   'naturaleza' => 'credito'],
            ['codigo' => '24',     'nombre' => 'Impuestos Sobre las Ventas',  'nivel' => 'clase',    'parent_codigo' => null,   'naturaleza' => 'credito'],
            ['codigo' => '1430',   'nombre' => 'Mercancías no Fabricadas',    'nivel' => 'cuenta',   'parent_codigo' => '14',   'naturaleza' => 'debito'],
            ['codigo' => '143005', 'nombre' => 'Inventario de Mercancías',    'nivel' => 'subcuenta','parent_codigo' => '1430', 'naturaleza' => 'debito'],
            ['codigo' => '2205',   'nombre' => 'Proveedores',                 'nivel' => 'cuenta',   'parent_codigo' => '22',   'naturaleza' => 'credito'],
            ['codigo' => '220505', 'nombre' => 'Proveedores Nacionales',      'nivel' => 'subcuenta','parent_codigo' => '2205', 'naturaleza' => 'credito'],
            ['codigo' => '2408',   'nombre' => 'IVA por Pagar',               'nivel' => 'cuenta',   'parent_codigo' => '24',   'naturaleza' => 'credito'],
            ['codigo' => '240810', 'nombre' => 'IVA Descontable en Compras',  'nivel' => 'auxiliar', 'parent_codigo' => '2408', 'naturaleza' => 'credito'],
            ['codigo' => '2365',   'nombre' => 'Retención en la Fuente',      'nivel' => 'cuenta',   'parent_codigo' => '23',   'naturaleza' => 'credito'],
            ['codigo' => '236540', 'nombre' => 'RetefuentE Compras',          'nivel' => 'subcuenta','parent_codigo' => '2365', 'naturaleza' => 'credito'],
            ['codigo' => '2368',   'nombre' => 'Industria y Comercio',        'nivel' => 'cuenta',   'parent_codigo' => '23',   'naturaleza' => 'credito'],
            ['codigo' => '236801', 'nombre' => 'ReteICA Compras',             'nivel' => 'subcuenta','parent_codigo' => '2368', 'naturaleza' => 'credito'],
            ['codigo' => '1105',   'nombre' => 'Caja',                        'nivel' => 'cuenta',   'parent_codigo' => '11',   'naturaleza' => 'debito'],
            ['codigo' => '110505', 'nombre' => 'Caja General',                'nivel' => 'subcuenta','parent_codigo' => '1105', 'naturaleza' => 'debito'],
        ];

        $ids = [];
        foreach ($cuentas as $row) {
            $parentId = isset($row['parent_codigo']) ? ($ids[$row['parent_codigo']] ?? null) : null;

            $cuenta = CuentaContable::firstOrCreate(
                ['codigo' => $row['codigo']],
                [
                    'nombre'             => $row['nombre'],
                    'naturaleza'         => $row['naturaleza'],
                    'nivel'              => $row['nivel'],
                    'parent_id'          => $parentId,
                    'acepta_movimientos' => in_array($row['nivel'], ['subcuenta', 'auxiliar'], true),
                    'exige_tercero'      => in_array($row['codigo'], ['220505', '236540', '236801'], true),
                    'exige_base_impuesto'=> false,
                ]
            );

            $ids[$row['codigo']] = $cuenta->id;
        }

        // Parametrización
        $parametros = [
            'compra.cuenta_proveedor'       => '220505',
            'compra.cuenta_inventario_merc' => '143005',
            'compra.cuenta_iva_descontable' => '240810',
            'compra.cuenta_retefuente'      => '236540',
            'compra.cuenta_reteica'         => '236801',
            'compra.cuenta_caja'            => '110505',
        ];

        foreach ($parametros as $clave => $codigo) {
            ParametrizacionContable::firstOrCreate(
                ['clave' => $clave],
                [
                    'cuenta_contable_id' => $ids[$codigo],
                    'descripcion'        => "Test: {$clave}",
                    'activo'             => true,
                ]
            );
        }
    }
}
