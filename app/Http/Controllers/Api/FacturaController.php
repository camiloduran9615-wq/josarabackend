<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Factura;
use App\Models\Tenant\Resolucion;
use App\Models\Tenant\TipoComprobante;
use App\Models\Tenant\Tercero;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use App\Services\FactusService;
use App\Services\FactusMappingService;
use App\Services\Inventario\CostoPromedioService;
use App\Services\Inventario\InventarioCuentaResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FacturaController extends Controller
{
    public function __construct(
        protected FactusService           $factusService,
        protected ContabilizadorService   $contabilizador,
        protected CostoPromedioService    $cpp,
        protected InventarioCuentaResolver $cuentaResolver,
    ) {}

    /**
     * Resuelve el DV (dígito de verificación) del NIT como string.
     *
     * Factus regla FAK24 exige el DV cuando el documento es NIT (id=31).
     * Si está guardado en BD, se devuelve como string.
     * Si NO está guardado y es NIT, se calcula con módulo 11 según DIAN
     * (Resolución 11004/2018, anexo técnico).
     *
     * Para cédulas/pasaportes devolvemos null (Factus no exige DV ahí).
     */
    private static function resolverDv(Tercero $t): ?string
    {
        // Si ya está guardado, usarlo
        if ($t->dv !== null && $t->dv !== '') {
            return (string) $t->dv;
        }

        // Solo calcular DV para NIT — para cédula no aplica
        // El documento_id puede ser '31' (NIT) o el código interno; verificamos ambos
        $esNit = in_array((string) $t->identificacion_documento_id, ['31', '6'], true);
        if (!$esNit) return null;

        return self::calcularDvNit((string) $t->identificacion);
    }

    /**
     * Calcula el DV de un NIT colombiano (módulo 11 — DIAN).
     * Algoritmo oficial: multiplica cada dígito por su peso, suma, calcula
     * residuo mod 11; el DV es 0, 1, o (11 - residuo).
     */
    private static function calcularDvNit(string $nit): string
    {
        // Limpiar (solo dígitos)
        $nit = preg_replace('/\D/', '', $nit);
        if ($nit === '') return '0';

        // Pesos DIAN del menos al más significativo
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $suma = 0;
        $digitos = array_reverse(str_split($nit));

        foreach ($digitos as $i => $d) {
            if (!isset($pesos[$i])) break;
            $suma += (int) $d * $pesos[$i];
        }

        $residuo = $suma % 11;
        if ($residuo === 0) return '0';
        if ($residuo === 1) return '1';
        return (string) (11 - $residuo);
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Factura::with('tercero')->orderBy('created_at', 'desc')->get()
        ]);
    }

    public function show($id)
    {
        $factura = Factura::with(['tercero', 'items', 'retenciones', 'resolucion'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $factura]);
    }

    /**
     * Envía un borrador o factura con error a Factus/DIAN.
     */
    public function enviar(Request $request, $id)
    {
        $factura = Factura::with(['tercero', 'items', 'retenciones', 'resolucion'])->findOrFail($id);

        // Permite actualizar payment_due_date al reintentar (ej: factura guardada sin ese campo)
        if ($request->has('payment_due_date')) {
            $request->validate([
                'payment_due_date' => 'nullable|date',
            ]);
            $factura->payment_due_date = $request->payment_due_date ?: null;
            $factura->save();
        }

        if (!in_array($factura->estado, ['borrador', 'error'])) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden enviar facturas en estado borrador o error.',
            ], 422);
        }

        $resolucion = $factura->resolucion;
        if (!$resolucion || !$resolucion->factus_id) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura no tiene una resolución DIAN con conexión a Factus. Configura la resolución en Ajustes.',
            ], 422);
        }

        $t = $factura->tercero;
        $payload = [
            'numbering_range_id' => $resolucion->factus_id,
            'reference_code'     => $factura->reference_code,
            'observation'        => $factura->observaciones ?? '',
            // Factus: payment_form como int (1=Contado, 2=Crédito); payment_method_code
            // como STRING con código DIAN UN/EDIFACT 4461 (10=Efectivo, 42=Consignación,
            // 47=Transferencia, 48=TC, 49=TD). Si se manda como int, Factus lo ignora
            // y aplica el default del numbering_range (Contado/Efectivo).
            'payment_form'        => (int) ($factura->payment_form ?? 1),
            'payment_method_code' => (string) ($factura->payment_method_code ?? '10'),
            'customer' => array_filter([
                'identification_document_id' => FactusMappingService::documentoId($t->identificacion_documento_id),
                'identification'             => (string) $t->identificacion,
                // Factus regla FAK24: DV se envía como string. Para NIT (doc 31) es obligatorio.
                // Si la BD no lo tiene pero es NIT, calcularlo vía módulo 11 según DIAN.
                'dv'                         => self::resolverDv($t),
                'company'                    => $t->razon_social ?: null,
                'trade_name'                 => $t->nombre_comercial ?: null,
                'names'                      => $t->nombres ? trim(($t->nombres ?? '') . ' ' . ($t->apellidos ?? '')) : null,
                'address'                    => $t->direccion ?: null,
                'email'                      => $t->email ?: null,
                'phone'                      => $t->telefono ?: null,
                'legal_organization_id'      => FactusMappingService::organizacionJuridicaId($t->organizacion_juridica_id, $t->identificacion_documento_id),
                'tribute_id'                 => FactusMappingService::tributoClienteId($t->tributo_id),
                'municipality_id'            => FactusMappingService::municipioId($t->municipio_id),
            ], fn($v) => $v !== null && $v !== ''),
            'withholding_taxes' => $factura->retenciones->map(fn($r) => [
                'code' => $r->codigo,
                'rate' => number_format((float) $r->tasa, 2, '.', ''),
            ])->toArray(),
            'items' => $factura->items->map(fn($item) => [
                'code_reference'   => $item->codigo_referencia ?? 'PROD-001',
                'name'             => $item->nombre,
                'quantity'         => (float) $item->cantidad,
                'discount_rate'    => 0.0,
                'price'            => (float) $item->precio_unitario,
                'tax_rate'         => (float) $item->porcentaje_iva,
                'unit_measure_id'  => 70,
                'standard_code_id' => 1,
                'is_excluded'      => 0,
                'tribute_id'       => FactusMappingService::tributoItemId((float) $item->porcentaje_iva),
            ])->toArray(),
        ];

        // payment_due_date requerido por Factus cuando payment_form = 2 (Crédito)
        if ((int) ($factura->payment_form ?? 1) === 2 && $factura->payment_due_date) {
            $payload['payment_due_date'] = $factura->payment_due_date->toDateString();
        }

        Log::info('Factus enviar payload', ['factura_id' => $id, 'payload' => $payload]);

        try {
            $result = $this->factusService->createBill($payload);

            if (isset($result['status']) && $result['status'] === 'Created') {
                $bill = $result['data']['bill'];
                $range = $result['data']['numbering_range'] ?? [];
                $factura->update([
                    'estado'           => 'validado',
                    'factus_bill_id'   => $bill['id'] ?? null,
                    'numero'           => (string) ($bill['number'] ?? ''),
                    'numero_completo'  => $bill['number'] ?? '',
                    'prefijo'          => $range['prefix'] ?? $resolucion->prefijo,
                    'cufe'             => $bill['cufe'] ?? null,
                    'qr_url'           => $bill['qr'] ?? null,
                    'public_url'       => $bill['public_url'] ?? null,
                    'valor_bruto'      => $bill['gross_value'] ?? 0,
                    'valor_impuestos'  => $bill['tax_amount'] ?? 0,
                    'valor_retenciones'=> 0,
                    'valor_total'      => $bill['total'] ?? 0,
                    'fecha_validacion' => now(),
                    'errores_api'      => null,
                ]);

                // El asiento de venta ya se generó al crear la factura (saveFactura);
                // aquí solo se actualiza el estado DIAN. No re-contabilizamos.

                return response()->json([
                    'success' => true,
                    'message' => 'Factura validada ante la DIAN correctamente.',
                    'data'    => $factura->fresh(['tercero']),
                ]);
            }

            $factura->update([
                'estado'      => 'error',
                'errores_api' => $result,
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Factus rechazó la factura.',
                'errors'  => $result,
            ], 422);

        } catch (\Exception $e) {
            $factura->update([
                'estado'      => 'error',
                'errores_api' => ['message' => $e->getMessage()],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al conectar con Factus: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtiene los rangos de numeración.
     * Intenta sincronizar desde Factus; si falla, devuelve resoluciones locales.
     */
    public function ranges()
    {
        try {
            $factusRanges = $this->factusService->getNumberingRanges();

            if ($factusRanges && isset($factusRanges['data'])) {
                foreach ($factusRanges['data'] as $range) {
                    Resolucion::updateOrCreate(
                        ['factus_id' => $range['id']],
                        [
                            'nombre'            => ($range['document'] ?? '') . ($range['prefix'] ? ' (' . $range['prefix'] . ')' : ''),
                            'prefijo'           => $range['prefix'] ?? null,
                            'desde'             => $range['from'] ?? 0,
                            'hasta'             => $range['to'] ?? 999999999,
                            'numero_resolucion' => $range['number'] ?? 'N/A',
                            'fecha_inicio'      => $range['start_date'] ?? now()->toDateString(),
                            'fecha_fin'         => $range['expiration_date'] ?? now()->addYears(5)->toDateString(),
                            'activa'            => $range['is_active'] ?? true,
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            // Sin credenciales Factus — usa resoluciones locales
        }

        $resoluciones = Resolucion::where('activa', true)
            ->orderBy('fecha_fin', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $resoluciones->map(fn($r) => [
                'id'              => $r->id,
                'factus_id'       => $r->factus_id,
                'name'            => $r->nombre,
                'prefix'          => $r->prefijo,
                'from'            => $r->desde,
                'to'              => $r->hasta,
                'number'          => $r->numero_resolucion,
                'start_date'      => $r->fecha_inicio,
                'expiration_date' => $r->fecha_fin,
                'is_local'        => !$r->factus_id,
            ]),
        ]);
    }

    /**
     * Crea una factura. Si la resolución tiene factus_id → valida en DIAN.
     * Si no, guarda como borrador local.
     */
    public function store(Request $request)
    {
        $request->validate([
            'tipo_comprobante_id'  => 'required|exists:tipo_comprobantes,id',
            'fecha_emision'        => 'required|date',
            'tercero_id'           => 'required|exists:terceros,id',
            'payment_form'         => 'required|string',
            'payment_method_code'  => 'required|string',
            'payment_due_date'     => 'required_if:payment_form,2|nullable|date|after_or_equal:fecha_emision',
            'observaciones'        => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.nombre'       => 'required|string',
            'items.*.descripcion'  => 'nullable|string',
            'items.*.cantidad'     => 'required|numeric|min:0.01',
            'items.*.precio'       => 'required|numeric|min:0',
            'items.*.descuento'    => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate'     => 'required|numeric|min:0',
            'items.*.producto_id'  => 'nullable|uuid|exists:productos,id',
            'items.*.bodega_id'    => 'nullable|uuid|exists:bodegas,id',
            'withholding_taxes'    => 'nullable|array',
            'withholding_taxes.*.code' => 'required|string',
            'withholding_taxes.*.rate' => 'required|numeric',
        ]);

        $comprobante = TipoComprobante::with('resolucion')->findOrFail($request->tipo_comprobante_id);
        $resolucion  = $comprobante->resolucion;
        $tercero     = Tercero::findOrFail($request->tercero_id);
        $referenceCode = 'FACT-' . Str::upper(Str::random(10));

        return DB::transaction(function () use ($request, $comprobante, $resolucion, $tercero, $referenceCode) {

            // Calcular totales
            $valorBruto = 0;
            $valorDescuentos = 0;
            $valorImpuestos = 0;

            foreach ($request->items as $item) {
                $subtotal = $item['cantidad'] * $item['precio'];
                $descuento = $subtotal * (($item['descuento'] ?? 0) / 100);
                $base = $subtotal - $descuento;
                $iva = $base * ($item['tax_rate'] / 100);
                $valorBruto += $subtotal;
                $valorDescuentos += $descuento;
                $valorImpuestos += $iva;
            }

            $valorRetenciones = collect($request->withholding_taxes ?? [])->reduce(
                fn($carry, $tax) => $carry + (($valorBruto - $valorDescuentos) * ($tax['rate'] / 100)),
                0
            );

            $valorTotal = $valorBruto - $valorDescuentos + $valorImpuestos - $valorRetenciones;

            // Incrementar consecutivo del comprobante
            $comprobante->increment('consecutivo_actual');

            // Si la resolución tiene factus_id → intentar validar en DIAN
            if ($resolucion && $resolucion->factus_id) {
                $payload = $this->buildFactusPayload($request, $resolucion, $tercero, $referenceCode);

                try {
                    $result = $this->factusService->createBill($payload);

                    if (isset($result['status']) && $result['status'] === 'Created') {
                        $bill  = $result['data']['bill'];
                        $range = $result['data']['numbering_range'] ?? [];
                        return $this->saveFactura($request, $comprobante, $resolucion, $tercero, $referenceCode, [
                            'estado'           => 'validado',
                            'factus_bill_id'   => $bill['id'] ?? null,
                            'numero'           => (string) ($bill['number'] ?? ''),
                            'numero_completo'  => $bill['number'] ?? '',
                            'prefijo'          => $range['prefix'] ?? $resolucion->prefijo,
                            'cufe'             => $bill['cufe'] ?? null,
                            'qr_url'           => $bill['qr'] ?? null,
                            'public_url'       => $bill['public_url'] ?? null,
                            'valor_bruto'      => $bill['gross_value'] ?? $valorBruto,
                            'valor_impuestos'  => $bill['tax_amount'] ?? $valorImpuestos,
                            'valor_retenciones'=> 0,
                            'valor_descuentos' => $valorDescuentos,
                            'valor_total'      => $bill['total'] ?? $valorTotal,
                            'fecha_validacion' => now(),
                        ]);
                    }

                    // Factus rechazó explícitamente → guardar como error con detalles
                    Log::warning('Factus rechazó la factura en store()', ['result' => $result]);
                    return $this->saveFactura($request, $comprobante, $resolucion, $tercero, $referenceCode, [
                        'estado'           => 'error',
                        'numero'           => '',
                        'numero_completo'  => '',
                        'prefijo'          => $resolucion->prefijo,
                        'valor_bruto'      => $valorBruto,
                        'valor_impuestos'  => $valorImpuestos,
                        'valor_retenciones'=> $valorRetenciones,
                        'valor_descuentos' => $valorDescuentos,
                        'valor_total'      => $valorTotal,
                        'errores_api'      => $result,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Factus conexión falló en store()', ['error' => $e->getMessage()]);
                    // Error de conexión → guardar como borrador (se puede reintentar)
                }
            }

            // Sin resolución o sin factus_id → borrador local
            return $this->saveFactura($request, $comprobante, $resolucion, $tercero, $referenceCode, [
                'estado'           => 'borrador',
                'numero'           => '',
                'numero_completo'  => '',
                'prefijo'          => $comprobante->prefijo_override ?? $resolucion?->prefijo,
                'valor_bruto'      => $valorBruto,
                'valor_impuestos'  => $valorImpuestos,
                'valor_retenciones'=> $valorRetenciones,
                'valor_descuentos' => $valorDescuentos,
                'valor_total'      => $valorTotal,
            ]);
        });
    }

    protected function buildFactusPayload(Request $request, Resolucion $resolucion, Tercero $tercero, string $referenceCode): array
    {
        $payload = [
            'numbering_range_id' => $resolucion->factus_id,
            'reference_code'     => $referenceCode,
            'observation'        => $request->observaciones ?? '',
            // Factus: payment_method_code debe ser STRING (código DIAN UN/EDIFACT 4461).
            // Si se manda como int, Factus aplica el default del numbering_range.
            'payment_form'        => (int) ($request->payment_form ?? 1),
            'payment_method_code' => (string) ($request->payment_method_code ?? '10'),
            'customer' => array_filter([
                'identification_document_id' => FactusMappingService::documentoId($tercero->identificacion_documento_id),
                'identification'             => (string) $tercero->identificacion,
                'dv'                         => self::resolverDv($tercero),
                'company'                    => $tercero->razon_social ?: null,
                'trade_name'                 => $tercero->nombre_comercial ?: null,
                'names'                      => $tercero->nombres ? trim(($tercero->nombres ?? '') . ' ' . ($tercero->apellidos ?? '')) : null,
                'address'                    => $tercero->direccion ?: null,
                'email'                      => $tercero->email ?: null,
                'phone'                      => $tercero->telefono ?: null,
                'legal_organization_id'      => FactusMappingService::organizacionJuridicaId($tercero->organizacion_juridica_id, $tercero->identificacion_documento_id),
                'tribute_id'                 => FactusMappingService::tributoClienteId($tercero->tributo_id),
                'municipality_id'            => FactusMappingService::municipioId($tercero->municipio_id),
            ], fn($v) => $v !== null && $v !== ''),
            'withholding_taxes' => collect($request->withholding_taxes ?? [])->map(fn($tax) => [
                'code' => $tax['code'],
                'rate' => number_format((float) $tax['rate'], 2, '.', ''),
            ])->toArray(),
            'items' => collect($request->items)->map(fn($item) => [
                'code_reference'   => $item['codigo'] ?? 'PROD-001',
                'name'             => $item['nombre'],
                'quantity'         => (float) $item['cantidad'],
                'discount_rate'    => (float) ($item['descuento'] ?? 0),
                'price'            => (float) $item['precio'],
                'tax_rate'         => (float) ($item['tax_rate'] ?? 19),
                'unit_measure_id'  => 70,   // 70 = "unidad" (código DIAN 94)
                'standard_code_id' => 1,    // 1 = Estándar de adopción del contribuyente
                'is_excluded'      => 0,
                'tribute_id'       => FactusMappingService::tributoItemId((float) ($item['tax_rate'] ?? 19)),
            ])->toArray(),
        ];

        // payment_due_date sólo cuando Crédito (payment_form = 2); omitir en Contado
        if ((int) ($request->payment_form ?? 1) === 2 && $request->payment_due_date) {
            $payload['payment_due_date'] = $request->payment_due_date;
        }

        Log::info('Factus payload enviado', ['payload' => $payload]);
        return $payload;
    }

    protected function saveFactura(Request $request, TipoComprobante $comprobante, ?Resolucion $resolucion, Tercero $tercero, string $referenceCode, array $extra): \Illuminate\Http\JsonResponse
    {
        $factura = Factura::create(array_merge([
            'tipo_documento'     => $comprobante->tipo_documento,
            'fecha_emision'      => $request->fecha_emision,
            'resolucion_id'      => $resolucion?->id,
            'numbering_range_id' => $resolucion?->factus_id,
            'tercero_id'         => $tercero->id,
            'reference_code'     => $referenceCode,
            'observaciones'       => $request->observaciones,
            'payment_form'        => $request->payment_form ?? '1',
            'payment_method_code' => $request->payment_method_code ?? '10',
            'payment_due_date'    => ($request->payment_form === '2') ? $request->payment_due_date : null,
        ], $extra));

        // Líneas del asiento de costo de ventas (Db 6135 / Cr 1435), si aplica
        $lineasCosto = [];

        foreach ($request->items as $item) {
            $taxRate   = (float) ($item['tax_rate'] ?? 19);
            $descuento = (float) ($item['descuento'] ?? 0);
            $subtotal  = $item['cantidad'] * $item['precio'];
            $base      = $subtotal * (1 - $descuento / 100);
            $cppUsado  = null;

            // ── Si el ítem tiene producto + bodega → descargar inventario ──
            if (! empty($item['producto_id']) && ! empty($item['bodega_id'])) {
                try {
                    [$movSalida, $cppUsado] = $this->cpp->registrarSalida(
                        productoId: $item['producto_id'],
                        bodegaId:   $item['bodega_id'],
                        cantidad:   (float) $item['cantidad'],
                        meta: [
                            'tipo'       => 'salida_venta',
                            'concepto'   => "Venta: {$factura->reference_code}",
                            'tercero_id' => $factura->tercero_id,
                            'factura_id' => $factura->id,
                        ],
                    );

                    // Acumular para el asiento de costo de ventas
                    $producto = \App\Models\Tenant\Producto::with(['categoria'])->find($item['producto_id']);
                    if ($producto) {
                        try {
                            $cuentaCosto = $this->cuentaResolver->resolverCostoVentas($producto);
                            $cuentaInv   = $this->cuentaResolver->resolverParaEntrada($producto);
                            $valorCosto  = round((float) $item['cantidad'] * $cppUsado, 2);

                            $lineasCosto[] = [
                                'costo_ventas_cuenta_id' => $cuentaCosto->id,
                                'inventario_cuenta_id'   => $cuentaInv->id,
                                'valor'                  => $valorCosto,
                                'descripcion'            => $item['nombre'],
                            ];
                        } catch (ParametrizacionFaltanteException) {
                            // Sin parametrización → omitir asiento de costo (loguear)
                            Log::warning('FacturaController: sin cuentas para costo de ventas', [
                                'producto_id' => $item['producto_id'],
                            ]);
                        }
                    }
                } catch (\App\Services\Inventario\Exceptions\StockInsuficienteException $e) {
                    Log::warning('FacturaController: stock insuficiente al vender', [
                        'producto_id' => $item['producto_id'],
                        'error'       => $e->getMessage(),
                    ]);
                    // Continúa — permitir venta sin stock si la configuración lo habilita
                }
            }

            $factura->items()->create([
                'codigo_referencia'   => $item['codigo'] ?? 'SERV-001',
                'nombre'              => $item['nombre'],
                'cantidad'            => $item['cantidad'],
                'precio_unitario'     => $item['precio'],
                'porcentaje_iva'      => $taxRate,
                'valor_iva'           => $base * ($taxRate / 100),
                'total'               => $base * (1 + $taxRate / 100),
                'bodega_id'           => $item['bodega_id'] ?? null,
                'costo_unitario_cpp'  => $cppUsado,
            ]);
        }

        $nombresRet = ['05' => 'Retefuente', '06' => 'ReteIVA', '07' => 'ReteICA'];
        foreach ($request->withholding_taxes ?? [] as $tax) {
            $factura->retenciones()->create([
                'codigo' => $tax['code'],
                'nombre' => $nombresRet[$tax['code']] ?? 'Otras Retenciones',
                'tasa'   => $tax['rate'],
                'valor'  => $factura->valor_bruto * ($tax['rate'] / 100),
                'base'   => $factura->valor_bruto,
            ]);
        }

        // Asiento contable FV (siempre, independientemente del estado Factus/DIAN):
        //   DB 130505 CxC   CR 413505 Ingresos   CR 240801 IVA   (+ líneas de costo: DB 6135 / CR 1435)
        $this->generarAsientoVenta($factura, $lineasCosto);

        $estadoMsg = match ($factura->estado) {
            'validado' => 'Factura validada ante la DIAN correctamente.',
            'error'    => 'La factura fue guardada pero Factus la rechazó. Puedes revisarla y reenviarla.',
            default    => 'Factura guardada como borrador (sin conexión DIAN).',
        };

        return response()->json([
            'success' => true,
            'message' => $estadoMsg,
            'data'    => $factura,
        ], 201);
    }

    /**
     * Genera el ÚNICO asiento contable de la factura de venta. Se ejecuta
     * siempre que la factura exista, independientemente del estado Factus/DIAN
     * (la validación DIAN es cumplimiento, no afecta partida doble).
     *
     * Estructura del asiento FV:
     *   DÉBITO  130505  CxC Clientes        → valor_total (neto a cobrar)
     *   CRÉDITO 413505  Ventas Mercancías   → valor_bruto - valor_descuentos
     *   CRÉDITO 240801  IVA por Pagar       → valor_impuestos  (si > 0)
     *   DÉBITO  613505  Costo de Ventas     → suma cantidad * CPP por ítem  (si hay líneas de costo)
     *   CRÉDITO 143005  Inventario          → mismo valor que costo
     *
     * Idempotente: si ya existe un asiento con origen=Factura $id no crea otro
     * (también lo garantiza el unique parcial unique_asiento_origen en PG).
     */
    private function generarAsientoVenta(Factura $factura, array $lineasCosto): void
    {
        if ($this->contabilizador->asientoExistenteDe($factura) !== null) {
            return;
        }

        $valorBase  = round((float) $factura->valor_bruto - (float) $factura->valor_descuentos, 2);
        $valorIva   = round((float) $factura->valor_impuestos, 2);
        $valorTotal = round((float) $factura->valor_total, 2);

        try {
            $cuentaCxc      = $this->contabilizador->cuenta('venta.cuenta_cxc');
            $cuentaIngresos = $this->contabilizador->cuenta('venta.cuenta_ingresos');

            $lineas = [
                [
                    'cuenta_contable_id' => $cuentaCxc->id,
                    'debito'             => $valorTotal,
                    'credito'            => 0.0,
                    'descripcion'        => "CxC Factura {$factura->reference_code}",
                    'tercero_id'         => $factura->tercero_id,
                ],
                [
                    'cuenta_contable_id' => $cuentaIngresos->id,
                    'debito'             => 0.0,
                    'credito'            => $valorBase,
                    'descripcion'        => "Ventas Factura {$factura->reference_code}",
                ],
            ];

            if ($valorIva > 0) {
                // BUG-010: IVA discriminado por tarifa para Formulario 300 DIAN.
                // BUG-044: items.valor_iva asume precio SIN IVA mientras factura.valor_impuestos
                // se calcula extrayéndolo del total (precio CON IVA). Discrepan cuando el usuario
                // ingresa "precio incluye IVA". Usamos $valorIva (de la factura) como total y
                // distribuimos proporcionalmente entre tarifas según los items.
                $factura->loadMissing('items');
                $ivaPorTarifaItems = $factura->items
                    ->groupBy(fn ($item) => (string) ((int) $item->porcentaje_iva))
                    ->map(fn ($items) => (float) $items->sum(fn ($i) => (float) $i->valor_iva));

                $totalIvaItems = $ivaPorTarifaItems->sum();
                // Escalar proporcionalmente si los items no suman exactamente lo que dice la factura
                if ($totalIvaItems > 0 && abs($totalIvaItems - $valorIva) > 0.01) {
                    $factor = $valorIva / $totalIvaItems;
                    $ivaPorTarifaItems = $ivaPorTarifaItems->map(fn ($v) => round($v * $factor, 2));
                }

                $cuentaIvaGenerica = $this->contabilizador->cuenta('venta.cuenta_iva_generado');

                // Ajuste final por redondeo: forzar que la suma sea exactamente $valorIva
                $sumaAjustada = 0.0;
                $ivaPorTarifaArray = $ivaPorTarifaItems->all();
                foreach ($ivaPorTarifaArray as $k => $v) $sumaAjustada += (float) $v;
                $delta = round($valorIva - $sumaAjustada, 2);
                if ($delta !== 0.0 && !empty($ivaPorTarifaArray)) {
                    $primeraTarifa = array_key_first($ivaPorTarifaArray);
                    $ivaPorTarifaArray[$primeraTarifa] = round($ivaPorTarifaArray[$primeraTarifa] + $delta, 2);
                }

                foreach ($ivaPorTarifaArray as $tarifa => $valorIvaTarifa) {
                    if ((float) $valorIvaTarifa <= 0) {
                        continue;
                    }
                    $clave = "venta.cuenta_iva_generado_{$tarifa}";
                    try {
                        $cuentaIvaTarifa = $this->contabilizador->cuenta($clave);
                    } catch (ParametrizacionFaltanteException) {
                        $cuentaIvaTarifa = $cuentaIvaGenerica;
                    }
                    $lineas[] = [
                        'cuenta_contable_id' => $cuentaIvaTarifa->id,
                        'debito'             => 0.0,
                        'credito'            => round((float) $valorIvaTarifa, 2),
                        'descripcion'        => "IVA {$tarifa}% Factura {$factura->reference_code}",
                    ];
                }
            }

            // ── Retenciones practicadas POR EL CLIENTE (anticipos a favor) ──────
            // Cuando el cliente es agente retenedor (gran contribuyente,
            // autorretenedor), descuenta la retención de su pago. Para nosotros
            // es un ANTICIPO que descontamos en la declaración de renta:
            //   DÉBITO 135515 Anticipo Retefuente   (lo que el cliente retiene)
            //   DÉBITO 135518 Anticipo ReteICA      (idem para ReteICA)
            // Si NO se contabilizan, el asiento queda desbalanceado en el monto
            // de la retención y ContabilizadorService lo rechaza silenciosamente.
            // Mapping código → clave de parametrización contable:
            $clavesPorCodigoRetencion = [
                '05' => 'factura.cuenta_retefuente_ventas',  // Retefuente compras
                '06' => 'factura.cuenta_reteiva_ventas',     // ReteIVA (opcional, no siempre parametrizado)
                '07' => 'factura.cuenta_reteica_ventas',     // ReteICA
            ];
            foreach ($factura->retenciones as $retencion) {
                $clave = $clavesPorCodigoRetencion[$retencion->codigo] ?? null;
                if ($clave === null) {
                    continue;
                }
                try {
                    $cuentaRet = $this->contabilizador->cuenta($clave);
                } catch (ParametrizacionFaltanteException) {
                    // Sin cuenta parametrizada para este código — saltar
                    // (mejor un asiento parcial que ninguno; loguear para alerta).
                    Log::warning('FacturaController: parametrización faltante para retención de venta', [
                        'factura_id' => $factura->id,
                        'clave'      => $clave,
                        'codigo_ret' => $retencion->codigo,
                    ]);
                    continue;
                }
                $lineas[] = [
                    'cuenta_contable_id' => $cuentaRet->id,
                    'debito'             => (float) $retencion->valor,
                    'credito'            => 0.0,
                    'descripcion'        => "Anticipo {$retencion->nombre} — Factura {$factura->reference_code}",
                    'tercero_id'         => $factura->tercero_id,
                ];
            }

            foreach ($lineasCosto as $lc) {
                $lineas[] = [
                    'cuenta_contable_id' => $lc['costo_ventas_cuenta_id'],
                    'debito'             => $lc['valor'],
                    'credito'            => 0.0,
                    'descripcion'        => "Costo de ventas: {$lc['descripcion']}",
                ];
                $lineas[] = [
                    'cuenta_contable_id' => $lc['inventario_cuenta_id'],
                    'debito'             => 0.0,
                    'credito'            => $lc['valor'],
                    'descripcion'        => "Salida inventario: {$lc['descripcion']}",
                ];
            }

            $this->contabilizador->contabilizar([
                'fecha'            => $factura->fecha_emision,
                'tipo_comprobante' => 'FV',
                'descripcion'      => "Venta — Factura {$factura->reference_code}",
                'origen'           => $factura,
                'created_by_id'    => auth()->id() ?? $factura->tercero_id,
                'lineas'           => $lineas,
            ]);
        } catch (ParametrizacionFaltanteException $e) {
            Log::warning('FacturaController: parametrización incompleta para asiento de venta', [
                'factura_id' => $factura->id,
                'error'      => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FacturaController: error generando asiento de venta', [
                'factura_id' => $factura->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
