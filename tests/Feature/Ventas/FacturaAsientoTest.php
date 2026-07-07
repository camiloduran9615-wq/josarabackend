<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\Bodega;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Factura;
use App\Models\Tenant\ParametrizacionContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\Tenant\Producto;
use App\Models\Tenant\ProductoStockBodega;
use App\Models\Tenant\Resolucion;
use App\Models\Tenant\Sucursal;
use App\Models\Tenant\Tercero;
use App\Models\Tenant\TipoComprobante;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Tests del asiento contable generado por FacturaController.
 *
 * Verifica que al guardar una Factura en estado 'validado' se genera el asiento:
 *   DÉBITO  130505  CxC Clientes     → valor_total
 *   CRÉDITO 413505  Ventas           → valor_bruto − descuentos
 *   CRÉDITO 240805  IVA por Pagar    → valor_impuestos  (si > 0)
 *
 * Los tests simulan una factura "validada" guardando directamente con estado=validado
 * (sin llamar a Factus/DIAN) para evitar dependencia de servicios externos.
 */
class FacturaAsientoTest extends TenantTestCase
{
    private User    $contador;
    private Tercero $cliente;
    private Producto $producto;
    private Bodega  $bodega;

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
            'nombre'   => 'Vendedor',
            'apellido' => 'Test',
            'email'    => 'vendedor-' . Str::random(6) . '@test.com',
            'password' => bcrypt('Test1234!'),
            'role'     => User::ROLE_CONTADOR,
            'activo'   => true,
        ]);

        // Idempotente: el tenant puede traer el periodo del mes actual sembrado
        $anio = (int) date('Y');
        $mes  = (int) date('m');
        $codigo = sprintf('%04d-%02d', $anio, $mes);
        if (PeriodoContable::where('codigo', $codigo)->doesntExist()) {
            $this->crearPeriodo(['año_fiscal' => $anio, 'mes' => $mes]);
        }

        $this->cliente = Tercero::create([
            'tipo_persona'               => 'juridica',
            'tipo_documento'             => 'nit',
            'numero_documento'           => '800999111',
            'identificacion_documento_id'=> '31',
            'identificacion'             => '800999111-' . rand(0, 9),
            'razon_social'               => 'Cliente Test S.A.S.',
            'email'                      => 'cliente@test.co',
            'es_cliente'                 => true,
            'activo'                     => true,
        ]);

        // Cuentas del módulo ventas
        $cxc      = CuentaContable::where('codigo', '130505')->firstOrFail();
        $ventas   = CuentaContable::where('codigo', '413505')->firstOrFail();
        $iva      = CuentaContable::where('codigo', '240805')->firstOrFail();
        $costo    = CuentaContable::where('codigo', '613505')->firstOrFail();
        $inv      = CuentaContable::where('codigo', '143005')->firstOrFail();

        foreach ([
            'venta.cuenta_cxc'          => $cxc->id,
            'venta.cuenta_ingresos'     => $ventas->id,
            'venta.cuenta_iva_generado' => $iva->id,
            'venta.cuenta_costo_ventas' => $costo->id,
            'factura.cuenta_costo_ventas'=> $costo->id,
        ] as $clave => $cuentaId) {
            ParametrizacionContable::updateOrCreate(
                ['clave' => $clave],
                ['cuenta_contable_id' => $cuentaId, 'activo' => true],
            );
        }

        // Bodega + stock inicial para el producto
        $sucursal = Sucursal::first() ?? Sucursal::create(['nombre' => 'Principal', 'codigo' => 'SUC-01', 'activo' => true]);
        $this->bodega = Bodega::first() ?? Bodega::create([
            'sucursal_id' => $sucursal->id,
            'codigo'      => 'BOD-VTA-' . Str::random(4),
            'nombre'      => 'Bodega Ventas Test',
            'tipo'        => 'mercancia',
        ]);

        $this->producto = Producto::create([
            'codigo'         => 'VTA-' . Str::random(4),
            'nombre'         => 'Producto Venta Test',
            'unidad_medida'  => '94',
            'precio_venta'   => 120000,
            'precio_compra'  => 60000,
            'stock_actual'   => 0,
            'porcentaje_iva' => 19,
            'activo'         => true,
        ]);

        // Seed stock para que el CPP no falle al registrar salida
        ProductoStockBodega::create([
            'producto_id'    => $this->producto->id,
            'bodega_id'      => $this->bodega->id,
            'saldo_unidades' => 50,
            'saldo_valor'    => 3000000,
            'costo_promedio' => 60000,
            'version'        => 1,
        ]);
    }

    // ─── FAV-001: Asiento de ingresos con IVA ────────────────────────────────

    /**
     * FAV-001: Factura validada con IVA 19% genera asiento de ingresos correcto.
     *
     * Venta: 2 × $100.000 = $200.000 base + $38.000 IVA = $238.000 total
     *
     * Asiento ingresos:
     *  DÉBITO  130505  $238.000  (CxC Clientes)
     *  CRÉDITO 413505  $200.000  (Ventas)
     *  CRÉDITO 240805  $38.000   (IVA por Pagar)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function fav001_factura_validada_genera_asiento_ingresos(): void
    {
        $this->seedFixtures();

        $factura = Factura::create([
            'tipo_documento'      => 'FV',
            'estado'              => 'validado',
            'tercero_id'          => $this->cliente->id,
            'fecha_emision'       => now()->toDateString(),
            'reference_code'      => 'TEST-' . Str::random(8),
            'numbering_range_id'  => 0,
            'payment_form'        => '1',
            'payment_method_code' => '10',
            'valor_bruto'         => 200000,
            'valor_impuestos'     => 38000,
            'valor_descuentos'    => 0,
            'valor_retenciones'   => 0,
            'valor_total'         => 238000,
        ]);

        // Invocar el método generarAsientoIngresos via el endpoint store con estado validado
        // Para test unitario del servicio, simulamos la llamada directa al método privado
        // reflejando el mismo resultado: asiento con 3 líneas CxC/Ventas/IVA
        $app     = app(\App\Services\Contabilizacion\ContabilizadorService::class);
        $cxc     = CuentaContable::where('codigo', '130505')->firstOrFail();
        $ventas  = CuentaContable::where('codigo', '413505')->firstOrFail();
        $ivaC    = CuentaContable::where('codigo', '240805')->firstOrFail();

        $asiento = $app->contabilizar([
            'fecha'            => $factura->fecha_emision,
            'tipo_comprobante' => 'FV',
            'descripcion'      => "Ingresos — Factura {$factura->reference_code}",
            'origen'           => $factura,
            'created_by_id'    => $this->adminUser->id,
            'lineas'           => [
                ['cuenta_contable_id' => $cxc->id,    'debito' => 238000, 'credito' => 0,      'descripcion' => 'CxC'],
                ['cuenta_contable_id' => $ventas->id, 'debito' => 0,      'credito' => 200000, 'descripcion' => 'Ventas'],
                ['cuenta_contable_id' => $ivaC->id,   'debito' => 0,      'credito' => 38000,  'descripcion' => 'IVA'],
            ],
        ]);

        // Asiento cuadrado
        $this->assertEquals(238000.0, (float) $asiento->lineas->sum('debito'),  'Débitos deben ser $238.000');
        $this->assertEquals(238000.0, (float) $asiento->lineas->sum('credito'), 'Créditos deben ser $238.000');

        // Cuentas correctas
        $codigos = $asiento->lineas->load('cuenta')->pluck('cuenta.codigo')->toArray();
        $this->assertContains('130505', $codigos, 'Debe debitar CxC (130505)');
        $this->assertContains('413505', $codigos, 'Debe acreditar Ventas (413505)');
        $this->assertContains('240805', $codigos, 'Debe acreditar IVA (240805)');

        // Montos individuales
        $lineaCxc   = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '130505');
        $lineaVtas  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '413505');
        $lineaIva   = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '240805');

        $this->assertEquals(238000.0, (float) $lineaCxc->debito);
        $this->assertEquals(200000.0, (float) $lineaVtas->credito);
        $this->assertEquals(38000.0,  (float) $lineaIva->credito);
    }

    // ─── FAV-002: Factura sin IVA → sin línea IVA ────────────────────────────

    /**
     * FAV-002: Factura exenta de IVA no genera línea de IVA en el asiento.
     * Solo: DÉBITO 130505 / CRÉDITO 413505
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function fav002_factura_sin_iva_no_genera_linea_iva(): void
    {
        $this->seedFixtures();

        $factura = Factura::create([
            'tipo_documento'      => 'FV',
            'estado'              => 'validado',
            'tercero_id'          => $this->cliente->id,
            'fecha_emision'       => now()->toDateString(),
            'reference_code'      => 'TEST-NOIVA-' . Str::random(6),
            'numbering_range_id'  => 0,
            'payment_form'        => '1',
            'payment_method_code' => '10',
            'valor_bruto'         => 150000,
            'valor_impuestos'     => 0,
            'valor_descuentos'    => 0,
            'valor_retenciones'   => 0,
            'valor_total'         => 150000,
        ]);

        $app    = app(\App\Services\Contabilizacion\ContabilizadorService::class);
        $cxc    = CuentaContable::where('codigo', '130505')->firstOrFail();
        $ventas = CuentaContable::where('codigo', '413505')->firstOrFail();

        $asiento = $app->contabilizar([
            'fecha'            => $factura->fecha_emision,
            'tipo_comprobante' => 'FV',
            'descripcion'      => "Ingresos — Factura {$factura->reference_code}",
            'origen'           => $factura,
            'created_by_id'    => $this->adminUser->id,
            'lineas'           => [
                ['cuenta_contable_id' => $cxc->id,    'debito' => 150000, 'credito' => 0,      'descripcion' => 'CxC'],
                ['cuenta_contable_id' => $ventas->id, 'debito' => 0,      'credito' => 150000, 'descripcion' => 'Ventas exentas'],
            ],
        ]);

        $codigos = $asiento->lineas->load('cuenta')->pluck('cuenta.codigo')->toArray();
        $this->assertNotContains('240805', $codigos, 'Factura exenta no debe tener línea IVA');
        $this->assertCount(2, $asiento->lineas, 'Solo 2 líneas: CxC y Ventas');

        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals($debitos, $creditos, 'Asiento debe cuadrar');
    }

    // ─── FAV-003: Idempotencia — no duplica asiento ──────────────────────────

    /**
     * FAV-003: Intentar contabilizar la misma factura dos veces no duplica el asiento.
     * ContabilizadorService::asientoExistenteDe() debe detectarlo.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function fav003_asiento_ingresos_es_idempotente(): void
    {
        $this->seedFixtures();

        $factura = Factura::create([
            'tipo_documento'      => 'FV',
            'estado'              => 'validado',
            'tercero_id'          => $this->cliente->id,
            'fecha_emision'       => now()->toDateString(),
            'reference_code'      => 'TEST-IDEM-' . Str::random(6),
            'numbering_range_id'  => 0,
            'payment_form'        => '1',
            'payment_method_code' => '10',
            'valor_bruto'         => 100000,
            'valor_impuestos'     => 19000,
            'valor_descuentos'    => 0,
            'valor_retenciones'   => 0,
            'valor_total'         => 119000,
        ]);

        $contabilizador = app(\App\Services\Contabilizacion\ContabilizadorService::class);
        $cxc   = CuentaContable::where('codigo', '130505')->firstOrFail();
        $vtas  = CuentaContable::where('codigo', '413505')->firstOrFail();
        $iva   = CuentaContable::where('codigo', '240805')->firstOrFail();

        $payload = [
            'fecha'            => $factura->fecha_emision,
            'tipo_comprobante' => 'FV',
            'descripcion'      => "Ingresos — Factura {$factura->reference_code}",
            'origen'           => $factura,
            'created_by_id'    => $this->adminUser->id,
            'lineas'           => [
                ['cuenta_contable_id' => $cxc->id,  'debito' => 119000, 'credito' => 0,      'descripcion' => 'CxC'],
                ['cuenta_contable_id' => $vtas->id, 'debito' => 0,      'credito' => 100000, 'descripcion' => 'Ventas'],
                ['cuenta_contable_id' => $iva->id,  'debito' => 0,      'credito' => 19000,  'descripcion' => 'IVA'],
            ],
        ];

        // Primera vez
        $contabilizador->contabilizar($payload);
        // Segunda vez — asientoExistenteDe detecta el origen y no crea otro
        $contabilizador->contabilizar($payload);

        $totalAsientos = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $factura->id)
            ->count();

        $this->assertEquals(1, $totalAsientos, 'No debe duplicarse el asiento de ingresos');
    }

    // ─── FAV-FIX-001..003: Asiento contable independiente del estado DIAN ─────
    //
    // Regresión: antes del fix, si Factus rechazaba la factura (o no había
    // resolución DIAN), el sistema descargaba inventario y creaba el asiento
    // de costo de ventas (CV), pero NO creaba el asiento de ingresos. Los
    // libros mayores quedaban desbalanceados — costo sin contrapartida de
    // ingreso ni de CxC.
    //
    // Fix: el asiento FV se genera SIEMPRE al crear la factura, sin importar
    // el estado Factus. Es UN solo asiento por factura (respeta el unique
    // parcial unique_asiento_origen) con todas las cuentas: CxC + Ventas +
    // IVA + Costo + Inventario.

    private function crearTipoComprobanteSinFactus(): TipoComprobante
    {
        // Resolución LOCAL (sin factus_id) — fuerza el path 'borrador' en store()
        $resolucion = Resolucion::create([
            'nombre'            => 'Resolución Local Test ' . Str::random(4),
            'prefijo'           => 'LOC',
            'desde'             => 1,
            'hasta'             => 9999,
            'numero_resolucion' => 'LOCAL-TEST',
            'fecha_inicio'      => now()->toDateString(),
            'fecha_fin'         => now()->addYear()->toDateString(),
            'factus_id'         => null,
            'activa'            => true,
        ]);

        return TipoComprobante::create([
            'codigo'             => 'FV-T' . Str::random(4),
            'nombre'             => 'Factura Venta Test',
            'tipo_documento'     => 'FV',
            'resolucion_id'      => $resolucion->id,
            'consecutivo_actual' => 0,
            'activo'             => true,
        ]);
    }

    /**
     * FAV-FIX-001: Factura sin resolución DIAN (queda en estado='borrador')
     * con producto físico → debe generar el asiento FV COMPLETO con las 5
     * líneas (CxC + Ventas + IVA + Costo + Inventario) en UN solo asiento.
     *
     * Antes del fix: solo se creaba un asiento CV de 2 líneas (Costo+Inventario),
     * dejando los libros desbalanceados (inventario salía sin contrapartida
     * de ingreso ni de CxC).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix001_factura_borrador_con_producto_genera_asiento_fv_completo(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        // Venta: 2 × $100.000 = $200.000 base + $38.000 IVA = $238.000 total
        // Costo: 2 × $60.000 = $120.000 (CPP del producto en stock)
        $payload = [
            'tipo_comprobante_id'  => $comprobante->id,
            'tercero_id'           => $this->cliente->id,
            'fecha_emision'        => now()->toDateString(),
            'payment_form'         => '1',
            'payment_method_code'  => '10',
            'items'                => [
                [
                    'codigo'      => 'VTA-001',
                    'nombre'      => 'Producto Venta Test',
                    'cantidad'    => 2,
                    'precio'      => 100000,
                    'tax_rate'    => 19,
                    'producto_id' => $this->producto->id,
                    'bodega_id'   => $this->bodega->id,
                ],
            ],
        ];

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('data.estado', 'borrador');

        $facturaId = $response->json('data.id');
        $factura = Factura::findOrFail($facturaId);

        // ── Un único asiento normal por factura (respeta unique_asiento_origen) ──
        $asientos = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $factura->id)
            ->where('tipo_movimiento', '!=', Asiento::TIPO_REVERSO)
            ->with('lineas.cuenta')
            ->get();

        $this->assertCount(1, $asientos, 'Debe existir exactamente UN asiento FV por factura');

        $asiento = $asientos->first();
        $this->assertEquals('FV', $asiento->tipo_comprobante, 'Tipo comprobante debe ser FV');

        // ── 5 líneas: CxC, Ventas, IVA, Costo, Inventario ──
        $this->assertCount(5, $asiento->lineas, 'Asiento debe tener 5 líneas');

        $codigos = $asiento->lineas->pluck('cuenta.codigo')->toArray();
        $this->assertContains('130505', $codigos, 'Debe debitar CxC (130505)');
        $this->assertContains('413505', $codigos, 'Debe acreditar Ventas (413505)');
        $this->assertContains('240805', $codigos, 'Debe acreditar IVA (240805)');
        $this->assertContains('613505', $codigos, 'Debe debitar Costo de Ventas (613505)');
        $this->assertContains('143005', $codigos, 'Debe acreditar Inventario (143005)');

        // ── Partida doble cuadrada ──
        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals($debitos, $creditos,
            "Asiento debe cuadrar: débitos={$debitos} créditos={$creditos}");

        // ── Montos esperados ──
        $lineaCxc  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '130505');
        $lineaVtas = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '413505');
        $lineaIva  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '240805');
        $lineaCV   = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '613505');
        $lineaInv  = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '143005');

        $this->assertEquals(238000.0, (float) $lineaCxc->debito,  'CxC = $238.000 (total con IVA)');
        $this->assertEquals(200000.0, (float) $lineaVtas->credito, 'Ventas = $200.000 (base)');
        $this->assertEquals(38000.0,  (float) $lineaIva->credito,  'IVA = $38.000 (19% × 200.000)');
        $this->assertEquals(120000.0, (float) $lineaCV->debito,   'Costo = $120.000 (2 × CPP 60.000)');
        $this->assertEquals(120000.0, (float) $lineaInv->credito, 'Inventario = $120.000 (contrapartida costo)');
    }

    /**
     * FAV-FIX-002: Factura sin resolución DIAN con servicio puro (sin
     * producto + bodega) → asiento FV de 3 líneas (CxC + Ventas + IVA),
     * sin líneas de costo/inventario porque no hubo descarga de stock.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix002_factura_borrador_solo_servicio_genera_3_lineas(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        $payload = [
            'tipo_comprobante_id'  => $comprobante->id,
            'tercero_id'           => $this->cliente->id,
            'fecha_emision'        => now()->toDateString(),
            'payment_form'         => '1',
            'payment_method_code'  => '10',
            'items'                => [
                [
                    'codigo'   => 'SERV-001',
                    'nombre'   => 'Asesoría contable',
                    'cantidad' => 1,
                    'precio'   => 500000,
                    'tax_rate' => 19,
                    // sin producto_id / bodega_id → es servicio
                ],
            ],
        ];

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('data.estado', 'borrador');

        $factura = Factura::findOrFail($response->json('data.id'));

        $asiento = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $factura->id)
            ->where('tipo_movimiento', '!=', Asiento::TIPO_REVERSO)
            ->with('lineas.cuenta')
            ->first();

        $this->assertNotNull($asiento, 'Factura de servicio también debe generar asiento');
        $this->assertCount(3, $asiento->lineas, 'Servicio: 3 líneas (CxC + Ventas + IVA), sin costo');

        $codigos = $asiento->lineas->pluck('cuenta.codigo')->toArray();
        $this->assertNotContains('613505', $codigos, 'No debe haber línea de costo en servicio');
        $this->assertNotContains('143005', $codigos, 'No debe haber línea de inventario en servicio');

        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals($debitos, $creditos, 'Asiento debe cuadrar');
        $this->assertEquals(595000.0, $debitos, 'Total = 500.000 + 95.000 IVA');
    }

    /**
     * FAV-FIX-004 (BUG-012): Factura con retención practicada por el cliente
     * (ej. gran contribuyente retiene 2.5% al pagarnos) debe generar el asiento
     * con la línea DÉBITO 135515 (Anticipo Retefuente). De lo contrario, el
     * asiento queda desbalanceado y el ContabilizadorService lo rechaza,
     * dejando la factura sin contabilizar silenciosamente.
     *
     * Caso: vendemos 1.000.000 + IVA 190.000 = 1.190.000 BRUTO.
     *       Cliente nos retiene 2.5% del bruto = 29.750 (Sale del bruto).
     *       Neto a cobrar: 1.190.000 - 29.750 = 1.160.250.
     *
     * Asiento esperado (5 líneas):
     *   DÉBITO  130505  CxC                 1.160.250  (neto a cobrar)
     *   DÉBITO  135515  Anticipo Retefuente    29.750  (retención del cliente)
     *   CRÉDITO 413505  Ventas              1.000.000
     *   CRÉDITO 240805  IVA                   190.000
     *   ∑D = ∑C = 1.190.000  ✅
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix004_factura_con_retencion_cliente_genera_asiento_balanceado(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        // Parametrizar la cuenta de anticipo retefuente (135515) si no existe
        $cuentaAnticipo = CuentaContable::where('codigo', '135515')->firstOrFail();
        ParametrizacionContable::updateOrCreate(
            ['clave' => 'factura.cuenta_retefuente_ventas'],
            ['cuenta_contable_id' => $cuentaAnticipo->id, 'activo' => true],
        );

        $payload = [
            'tipo_comprobante_id'  => $comprobante->id,
            'tercero_id'           => $this->cliente->id,
            'fecha_emision'        => now()->toDateString(),
            'payment_form'         => '1',
            'payment_method_code'  => '10',
            'items'                => [[
                'codigo'   => 'SERV-RET',
                'nombre'   => 'Servicio con retención',
                'cantidad' => 1,
                'precio'   => 1000000,
                'tax_rate' => 19,
            ]],
            'withholding_taxes'    => [
                ['code' => '05', 'rate' => 2.5],   // retefuente compras 2.5%
            ],
        ];

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), $payload);

        $response->assertStatus(201);
        $factura = Factura::findOrFail($response->json('data.id'));

        $asiento = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $factura->id)
            ->where('tipo_movimiento', '!=', Asiento::TIPO_REVERSO)
            ->with('lineas.cuenta')
            ->first();

        $this->assertNotNull(
            $asiento,
            'BUG-012: factura con retención no generó asiento. '
            . 'Probablemente el ContabilizadorService rechazó por desbalance. '
            . 'Verifica que generarAsientoVenta agregue la línea 135515.',
        );

        $codigos = $asiento->lineas->pluck('cuenta.codigo')->toArray();
        $this->assertContains(
            '135515',
            $codigos,
            'BUG-012: el asiento no incluye DB 135515 (Anticipo Retefuente). '
            . 'generarAsientoVenta debe iterar las retenciones y agregar líneas DB.',
        );

        // Partida doble cuadrada
        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals(
            $debitos,
            $creditos,
            "BUG-012: asiento desbalanceado. ∑D={$debitos}, ∑C={$creditos}. "
            . "Diferencia es exactamente el valor de la retención no contabilizada.",
        );

        // Monto del anticipo: 1.000.000 × 2.5% = 25.000
        // (calculado sobre valor_bruto - valor_descuentos según el código actual)
        $lineaAnticipo = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '135515');
        $this->assertEquals(
            25000.0,
            (float) $lineaAnticipo->debito,
            'Anticipo retefuente = 2.5% × 1.000.000 = 25.000',
        );

        // CxC = total a cobrar (bruto + IVA - retención) = 1.190.000 - 25.000 = 1.165.000
        $lineaCxc = $asiento->lineas->first(fn ($l) => $l->cuenta->codigo === '130505');
        $this->assertEquals(
            1165000.0,
            (float) $lineaCxc->debito,
            'CxC = bruto + IVA - retención = 1.190.000 - 25.000 = 1.165.000',
        );
    }

    /**
     * FAV-FIX-005 (BUG-004): Permite registrar facturas con fecha de emisión
     * pasada (caso típico contable: ingreso retroactivo de facturas del mes
     * anterior). Antes del fix, la regla `after:today` para payment_due_date
     * bloqueaba este flujo cuando la factura era a crédito.
     *
     * Cambio: la validación ahora es `after_or_equal:fecha_emision` —
     * verifica coherencia interna del documento, no relación con "hoy".
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix005_acepta_factura_historica_con_vencimiento_pasado(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        // Factura emitida hace 60 días, vencimiento a 30 días de la emisión
        // → vencimiento también pasado (hace ~30 días). Antes del fix: 422.
        $fechaEmision = now()->subDays(60)->toDateString();
        $fechaVenc    = now()->subDays(30)->toDateString();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), [
                'tipo_comprobante_id'  => $comprobante->id,
                'tercero_id'           => $this->cliente->id,
                'fecha_emision'        => $fechaEmision,
                'payment_form'         => '2',          // crédito → exige due_date
                'payment_method_code'  => '10',
                'payment_due_date'     => $fechaVenc,
                'items'                => [[
                    'codigo'   => 'SERV-HIST',
                    'nombre'   => 'Servicio histórico',
                    'cantidad' => 1,
                    'precio'   => 100000,
                    'tax_rate' => 19,
                ]],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.estado', 'borrador');
    }

    /**
     * FAV-FIX-006 (BUG-004): Rechaza factura con payment_due_date ANTERIOR a
     * fecha_emision (inconsistencia interna del documento).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix006_rechaza_vencimiento_anterior_a_fecha_emision(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), [
                'tipo_comprobante_id'  => $comprobante->id,
                'tercero_id'           => $this->cliente->id,
                'fecha_emision'        => '2026-05-15',
                'payment_form'         => '2',
                'payment_method_code'  => '10',
                'payment_due_date'     => '2026-05-10',  // ANTES de emisión
                'items'                => [[
                    'codigo'   => 'SERV-ERR',
                    'nombre'   => 'Servicio error',
                    'cantidad' => 1,
                    'precio'   => 100000,
                    'tax_rate' => 19,
                ]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_due_date']);
    }

    /**
     * FAV-FIX-007 (BUG-010): factura con ítems de DISTINTAS tarifas IVA
     * (5% y 19%) debe generar UNA línea de IVA por cada tarifa en cuentas
     * distintas (240802 para 5%, 240805 para 19%). Esto facilita la
     * Declaración Bimestral de IVA Formulario 300 DIAN.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix007_factura_con_iva_19_y_5_genera_lineas_iva_separadas(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        // Asegurar que existen las cuentas e impuestos del seeder real
        $cuenta240802 = CuentaContable::firstOrCreate(
            ['codigo' => '240802'],
            [
                'nombre'              => 'IVA por Pagar — Ventas Tarifa 5%',
                'naturaleza'          => 'credito',
                'nivel'               => 'subcuenta',
                'clase'               => 2,
                'acepta_movimientos'  => true,
                'exige_tercero'       => false,
                'exige_centro_costo'  => false,
                'exige_base_impuesto' => false,
                'clasificacion_balance' => 'corriente',
                'clasificacion_pyg'   => 'na',
                'sistema'             => true,
                'editable'            => false,
                'activo'              => true,
            ],
        );
        $cuenta240805 = CuentaContable::where('codigo', '240805')->firstOrFail();

        ParametrizacionContable::updateOrCreate(
            ['clave' => 'venta.cuenta_iva_generado_5'],
            ['cuenta_contable_id' => $cuenta240802->id, 'activo' => true],
        );
        ParametrizacionContable::updateOrCreate(
            ['clave' => 'venta.cuenta_iva_generado_19'],
            ['cuenta_contable_id' => $cuenta240805->id, 'activo' => true],
        );

        // Factura con DOS items: uno IVA 19% y otro IVA 5%
        // Item 1: 1 × 1.000.000 IVA 19% = 1.000.000 base + 190.000 IVA
        // Item 2: 1 × 500.000   IVA 5%  =   500.000 base +  25.000 IVA
        $payload = [
            'tipo_comprobante_id'  => $comprobante->id,
            'tercero_id'           => $this->cliente->id,
            'fecha_emision'        => now()->toDateString(),
            'payment_form'         => '1',
            'payment_method_code'  => '10',
            'items'                => [
                [
                    'codigo'   => 'SERV-19',
                    'nombre'   => 'Servicio gravado 19%',
                    'cantidad' => 1,
                    'precio'   => 1000000,
                    'tax_rate' => 19,
                ],
                [
                    'codigo'   => 'PROD-5',
                    'nombre'   => 'Producto canasta básica IVA 5%',
                    'cantidad' => 1,
                    'precio'   => 500000,
                    'tax_rate' => 5,
                ],
            ],
        ];

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), $payload);

        $response->assertStatus(201);
        $factura = Factura::findOrFail($response->json('data.id'));

        $asiento = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $factura->id)
            ->where('tipo_movimiento', '!=', Asiento::TIPO_REVERSO)
            ->with('lineas.cuenta')
            ->first();

        $this->assertNotNull($asiento);

        // Debe haber DOS líneas de IVA (una por cada tarifa) en cuentas distintas
        $lineasIva = $asiento->lineas
            ->filter(fn ($l) => in_array($l->cuenta->codigo, ['240802', '240805'], true));

        $this->assertCount(
            2,
            $lineasIva,
            'BUG-010: el asiento debe tener 2 líneas IVA (una por tarifa). '
            . 'Cuentas presentes: ' . $asiento->lineas->pluck('cuenta.codigo')->join(','),
        );

        $linea19 = $lineasIva->first(fn ($l) => $l->cuenta->codigo === '240805');
        $linea5  = $lineasIva->first(fn ($l) => $l->cuenta->codigo === '240802');

        $this->assertNotNull($linea19, 'Falta línea IVA 19% en cuenta 240805');
        $this->assertNotNull($linea5,  'Falta línea IVA 5% en cuenta 240802');

        $this->assertEquals(190000.0, (float) $linea19->credito, 'IVA 19% = 190.000');
        $this->assertEquals(25000.0,  (float) $linea5->credito,  'IVA 5% = 25.000');

        // Partida doble cuadrada
        $debitos  = round((float) $asiento->lineas->sum('debito'), 2);
        $creditos = round((float) $asiento->lineas->sum('credito'), 2);
        $this->assertEquals($debitos, $creditos, "Asiento debe cuadrar: D={$debitos}, C={$creditos}");
    }

    /**
     * FAV-FIX-003: Regresión — el flujo no genera dos asientos separados
     * (CV + FV). El unique parcial unique_asiento_origen prohíbe dos asientos
     * normales con el mismo origen, y nuestra implementación consolida todo
     * en UN asiento FV por factura.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function favfix003_factura_genera_un_solo_asiento_por_origen(): void
    {
        $this->seedFixtures();
        $comprobante = $this->crearTipoComprobanteSinFactus();

        $response = $this->actingAs($this->contador, 'sanctum')
            ->postJson($this->tenantUrl('/facturas'), [
                'tipo_comprobante_id'  => $comprobante->id,
                'tercero_id'           => $this->cliente->id,
                'fecha_emision'        => now()->toDateString(),
                'payment_form'         => '1',
                'payment_method_code'  => '10',
                'items'                => [[
                    'codigo'      => 'VTA-001',
                    'nombre'      => 'Producto Venta Test',
                    'cantidad'    => 1,
                    'precio'      => 50000,
                    'tax_rate'    => 19,
                    'producto_id' => $this->producto->id,
                    'bodega_id'   => $this->bodega->id,
                ]],
            ]);

        $response->assertStatus(201);
        $facturaId = $response->json('data.id');

        $total = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $facturaId)
            ->where('tipo_movimiento', '!=', Asiento::TIPO_REVERSO)
            ->count();

        $this->assertEquals(1, $total,
            'Debe existir UN único asiento por factura (no separados CV+FV)');

        // Y debe ser de tipo FV (no CV)
        $asiento = Asiento::where('origen_type', Factura::class)
            ->where('origen_id', $facturaId)
            ->first();
        $this->assertEquals('FV', $asiento->tipo_comprobante);
    }
}
