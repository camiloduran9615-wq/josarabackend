<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\Bodega;
use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\PeriodoContable;
use App\Models\Tenant\Producto;
use App\Models\Tenant\TipoDocumentoIngreso;
use App\Services\AuditLogService;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use App\Services\Inventario\CostoPromedioService;
use App\Services\Inventario\InventarioCuentaResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona Documentos de Ingreso v2 (multi-bodega, asiento dinámico).
 *
 * Flujo al registrar una Factura de Compra (tipo = factura_compra):
 *
 *  1. Valida que el período esté abierto.
 *  2. Guarda encabezado (con sucursal_id, centro_costo_id) + ítems (con bodega_id, tipo_linea).
 *  3. Por cada ítem con tipo_linea='producto':
 *       - CostoPromedioService::registrarEntrada() → movimiento KARDEX + recalcula CPP
 *       - Línea débito en el asiento → cuenta resuelta por InventarioCuentaResolver
 *  4. Por cada ítem con tipo_linea='gasto':
 *       - NO toca inventario.
 *       - Línea débito directo a cuenta_gasto_id del ítem (o fallback 519500).
 *  5. ContabilizadorService::contabilizar() genera el asiento con todas las líneas.
 *  6. Registra en AuditLog.
 *
 * Cuentas PUC involucradas (resueltas dinámicamente):
 *  Débito  → bodega.inventario_cuenta_id ?: producto.inventario_cuenta_id
 *            ?: categoria.inventario_cuenta_id ?: parametrizacion[tipo]
 *  Débito  → compra.cuenta_iva_descontable (240810) si hay IVA
 *  Crédito → compra.cuenta_proveedor (220505) si crédito
 *           → compra.cuenta_caja (110505) si contado
 *  Crédito → compra.cuenta_retefuente (236540) si hay retefuente
 *  Crédito → compra.cuenta_reteica (236801) si hay reteica
 */
class DocumentoIngresoController extends Controller
{
    public function __construct(
        private readonly ContabilizadorService  $contabilizador,
        private readonly CostoPromedioService   $cpp,
        private readonly InventarioCuentaResolver $cuentaResolver,
        private readonly AuditLogService        $auditLog,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /documentos-ingreso
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = DocumentoIngreso::with(['tercero', 'sucursal'])
            ->orderBy('fecha', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('tipo'))        $query->where('tipo', $request->tipo);
        if ($request->filled('estado'))      $query->where('estado', $request->estado);
        if ($request->filled('tercero_id'))  $query->where('tercero_id', $request->tercero_id);
        if ($request->filled('sucursal_id')) $query->where('sucursal_id', $request->sucursal_id);

        $docs = $query->paginate((int) ($request->per_page ?? 25));

        return response()->json([
            'success' => true,
            'data'    => $docs->items(),
            'meta'    => [
                'total'        => $docs->total(),
                'per_page'     => $docs->perPage(),
                'current_page' => $docs->currentPage(),
                'last_page'    => $docs->lastPage(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /documentos-ingreso
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tercero_id'                      => ['required', 'uuid', 'exists:terceros,id'],
            'tipo_documento_ingreso_id'       => ['nullable', 'uuid', 'exists:tipos_documento_ingreso,id'],
            'tipo'                            => ['nullable', 'in:factura_compra,cuenta_cobro,gasto,otro'],
            'fecha'                           => ['required', 'date'],
            'fecha_vencimiento'               => ['nullable', 'date', 'after_or_equal:fecha'],
            'concepto'                        => ['required', 'string', 'max:500'],
            'forma_pago'                      => ['required', 'in:contado,contado_efectivo,contado_banco,credito'],
            'sucursal_id'                     => ['nullable', 'uuid', 'exists:sucursales,id'],
            'centro_costo_id'                 => ['nullable', 'uuid', 'exists:centros_costo,id'],
            'observaciones'                   => ['nullable', 'string', 'max:1000'],
            'numero_documento_proveedor'      => ['nullable', 'string', 'max:100'],
            'valor_retefuente'                => ['nullable', 'numeric', 'min:0'],
            'valor_reteica'                   => ['nullable', 'numeric', 'min:0'],
            'valor_reteiva'                   => ['nullable', 'numeric', 'min:0'],

            // Ítems
            'items'                           => ['required', 'array', 'min:1'],
            'items.*.tipo_linea'              => ['required', 'in:producto,gasto,activo_fijo'],
            'items.*.descripcion'             => ['required', 'string', 'max:500'],
            'items.*.cantidad'                => ['required', 'numeric', 'min:0.001'],
            'items.*.precio_unitario'         => ['required', 'numeric', 'min:0'],
            'items.*.porcentaje_iva'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            // producto: requerido si tipo_linea=producto
            'items.*.producto_id'             => ['required_if:items.*.tipo_linea,producto', 'nullable', 'uuid', 'exists:productos,id'],
            'items.*.bodega_id'               => ['required_if:items.*.tipo_linea,producto', 'nullable', 'uuid', 'exists:bodegas,id'],
            // gasto/activo_fijo: cuenta contable directa
            'items.*.cuenta_id'               => ['required_if:items.*.tipo_linea,gasto', 'required_if:items.*.tipo_linea,activo_fijo', 'nullable', 'uuid', 'exists:cuentas_contables,id'],
        ]);

        // ── SC-006: Deduplicación por numero_documento_proveedor ────────────
        if (! empty($validated['numero_documento_proveedor'])) {
            $existe = DocumentoIngreso::where('tercero_id', $validated['tercero_id'])
                ->where('numero_documento_proveedor', $validated['numero_documento_proveedor'])
                ->exists();
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => "El documento del proveedor '{$validated['numero_documento_proveedor']}' ya fue registrado para este tercero.",
                    'errors'  => ['numero_documento_proveedor' => ['Este número de documento ya existe para el tercero indicado.']],
                ], 422);
            }
        }

        // ── Verificar periodo abierto ────────────────────────────────────────
        $periodo = PeriodoContable::actual(now());
        if ($periodo === null || $periodo->estado !== 'abierto') {
            return response()->json([
                'success' => false,
                'message' => 'No hay periodo contable abierto para hoy. Crea o abre un período antes de registrar documentos.',
            ], 422);
        }

        // ── Cargar tipo parametrizado (si viene) ────────────────────────────
        /** @var TipoDocumentoIngreso|null $tipoDoc */
        $tipoDoc = isset($validated['tipo_documento_ingreso_id'])
            ? TipoDocumentoIngreso::find($validated['tipo_documento_ingreso_id'])
            : null;

        // El tipo puede venir explícito o inferido del TipoDocumentoIngreso
        if (empty($validated['tipo'])) {
            $validated['tipo'] = $tipoDoc ? 'factura_compra' : 'otro';
        }

        return DB::transaction(function () use ($validated, $periodo, $tipoDoc): JsonResponse {

            // ── 1. Calcular totales ─────────────────────────────────────────
            $bruto     = 0.0;
            $totalIva  = 0.0;
            $itemsProc = [];

            foreach ($validated['items'] as $item) {
                $pct      = (float) ($item['porcentaje_iva'] ?? 0);
                $subtotal = round((float) $item['cantidad'] * (float) $item['precio_unitario'], 2);
                $ivaItem  = round($subtotal * ($pct / 100), 2);
                $bruto   += $subtotal;
                $totalIva += $ivaItem;

                $itemsProc[] = array_merge($item, [
                    'porcentaje_iva' => $pct,
                    'valor_iva'      => $ivaItem,
                    'total'          => round($subtotal + $ivaItem, 2),
                    'subtotal'       => $subtotal,
                ]);
            }

            $retefuente = (float) ($validated['valor_retefuente'] ?? 0);
            $reteica    = (float) ($validated['valor_reteica']    ?? 0);
            $reteiva    = (float) ($validated['valor_reteiva']    ?? 0);
            $total      = round($bruto + $totalIva - $retefuente - $reteica - $reteiva, 2);

            // ── 2. Crear encabezado ─────────────────────────────────────────
            $numero = 'ING-' . str_pad(
                (string) (DocumentoIngreso::withTrashed()->count() + 1),
                6, '0', STR_PAD_LEFT
            );

            /** @var DocumentoIngreso $doc */
            $doc = DocumentoIngreso::create([
                'tercero_id'                 => $validated['tercero_id'],
                'sucursal_id'                => $validated['sucursal_id'] ?? null,
                'centro_costo_id'            => $validated['centro_costo_id'] ?? null,
                'tipo_documento_ingreso_id'  => $tipoDoc?->id,
                'numero'                     => $numero,
                'tipo'                       => $validated['tipo'],
                'fecha'                      => $validated['fecha'],
                'fecha_vencimiento'          => $validated['fecha_vencimiento'] ?? null,
                'concepto'                   => $validated['concepto'],
                'forma_pago'                 => $validated['forma_pago'],
                'valor_bruto'                => round($bruto, 2),
                'valor_iva'                  => round($totalIva, 2),
                'valor_retefuente'           => $retefuente,
                'valor_reteica'              => $reteica,
                'valor_reteiva'              => $reteiva,
                'valor_total'                => $total,
                'estado'                     => 'registrado',
                'observaciones'              => $validated['observaciones'] ?? null,
                'numero_documento_proveedor' => $validated['numero_documento_proveedor'] ?? null,
            ]);

            // ── 3. Crear ítems + mover inventario (solo productos) ──────────
            $lineasAsiento = [];

            foreach ($itemsProc as $item) {
                // Persistir ítem
                $doc->items()->create([
                    'producto_id'    => $item['producto_id'] ?? null,
                    'bodega_id'      => $item['bodega_id'] ?? null,
                    'cuenta_id'      => $item['cuenta_id'] ?? null,
                    'tipo_linea'     => $item['tipo_linea'],
                    'descripcion'    => $item['descripcion'],
                    'cantidad'       => $item['cantidad'],
                    'precio_unitario'=> $item['precio_unitario'],
                    'porcentaje_iva' => $item['porcentaje_iva'],
                    'valor_iva'      => $item['valor_iva'],
                    'total'          => $item['total'],
                ]);

                // ── Ítem tipo 'producto': entrada de inventario ────────────
                if ($item['tipo_linea'] === 'producto' && ! empty($item['producto_id'])) {
                    $producto = Producto::with(['categoria'])->findOrFail($item['producto_id']);
                    $bodega   = Bodega::findOrFail($item['bodega_id']);

                    // Registrar entrada con CPP recalculado
                    $this->cpp->registrarEntrada(
                        productoId:    $producto->id,
                        bodegaId:      $bodega->id,
                        cantidad:      (float) $item['cantidad'],
                        costoUnitario: (float) $item['precio_unitario'],
                        meta: [
                            'tipo'                 => 'entrada_compra',
                            'concepto'             => "Compra: {$doc->numero}",
                            'tercero_id'           => $doc->tercero_id,
                            'centro_costo_id'      => $doc->centro_costo_id,
                            'documento_ingreso_id' => $doc->id,
                        ],
                    );

                    // Resolver cuenta de inventario (dinámica por categoría)
                    $cuentaInv = $this->cuentaResolver->resolverParaEntrada($producto, $bodega);

                    $lineasAsiento[] = [
                        'cuenta_contable_id'   => $cuentaInv->id,
                        'tercero_id'           => null,
                        'debito'               => $item['subtotal'],
                        'credito'              => 0.0,
                        'descripcion'          => $item['descripcion'],
                        'documento_referencia' => $doc->numero,
                    ];
                }

                // ── Ítem tipo 'gasto' o 'activo_fijo': débito directo ──────
                if (in_array($item['tipo_linea'], ['gasto', 'activo_fijo']) && ! empty($item['cuenta_id'])) {
                    $lineasAsiento[] = [
                        'cuenta_contable_id'   => $item['cuenta_id'],
                        'tercero_id'           => null,
                        'debito'               => $item['subtotal'],
                        'credito'              => 0.0,
                        'descripcion'          => $item['descripcion'],
                        'documento_referencia' => $doc->numero,
                    ];
                }

                // ── IVA descontable (todos los tipos) ─────────────────────
                if ($item['valor_iva'] > 0) {
                    // Prioridad: cuenta override del tipo → parametrización global
                    $cuentaIva = $tipoDoc?->cuenta_iva_descontable_id
                        ? \App\Models\Tenant\CuentaContable::findOrFail($tipoDoc->cuenta_iva_descontable_id)
                        : $this->contabilizador->cuenta('compra.cuenta_iva_descontable');
                    $lineasAsiento[] = [
                        'cuenta_contable_id'   => $cuentaIva->id,
                        'tercero_id'           => null,
                        'debito'               => $item['valor_iva'],
                        'credito'              => 0.0,
                        'descripcion'          => "IVA compra: {$item['descripcion']}",
                        'documento_referencia' => $doc->numero,
                    ];
                }
            }

            // ── 4. Generar asiento ──────────────────────────────────────────
            // Todos los tipos contables generan asiento: factura_compra,
            // cuenta_cobro (honorarios), gasto, activo_fijo. Solo se omite
            // si no hay líneas (caso defensivo) o si falta parametrización.
            $tiposContables = ['factura_compra', 'cuenta_cobro', 'gasto', 'activo_fijo'];
            if (in_array($validated['tipo'], $tiposContables, true) && ! empty($lineasAsiento)) {
                try {
                    $asiento = $this->generarAsientoCompra(
                        doc:          $doc,
                        lineasDebito: $lineasAsiento,
                        retefuente:   $retefuente,
                        reteica:      $reteica,
                        tipoDoc:      $tipoDoc,
                    );
                    $doc->update(['asiento_id' => $asiento->id]);
                } catch (ParametrizacionFaltanteException $e) {
                    logger()->warning('DocumentoIngreso: parametrización faltante', [
                        'documento_ingreso_id' => $doc->id,
                        'error'                => $e->getMessage(),
                    ]);
                }
            }

            // ── 5. Auditoría ────────────────────────────────────────────────
            $this->auditLog->record(
                action:     'documento_ingreso.creado',
                criticidad: AuditLogService::CRITICIDAD_INFO,
                auditable:  $doc,
                newValues:  [
                    'tipo'       => $doc->tipo,
                    'numero'     => $doc->numero,
                    'total'      => $doc->valor_total,
                    'sucursal'   => $doc->sucursal_id,
                ],
            );

            return response()->json([
                'success' => true,
                'message' => "Documento {$doc->numero} registrado correctamente.",
                'data'    => $doc->load(['tercero', 'sucursal', 'items.producto', 'asiento']),
            ], 201);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /documentos-ingreso/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(string $id): JsonResponse
    {
        $doc = DocumentoIngreso::with([
            'tercero',
            'sucursal',
            'centroCosto',
            'items.producto.categoria',
            'items.bodega.sucursal',
            'items.cuenta',
            'asiento.lineas.cuenta',
            'movimientosInventario.producto',
            'movimientosInventario.bodega',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $doc]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /documentos-ingreso/{id}  — Anulación
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(string $id): JsonResponse
    {
        // withTrashed permite detectar documentos ya anulados (soft-deleted) y
        // devolver 422 en lugar de 404 en un segundo intento de anulación.
        $doc = DocumentoIngreso::withTrashed()->with('movimientosInventario')->findOrFail($id);

        if ($doc->estado === 'anulado' || $doc->trashed()) {
            return response()->json(['success' => false, 'message' => 'El documento ya está anulado.'], 422);
        }

        DB::transaction(function () use ($doc): void {
            // Reversar cada movimiento de inventario usando CostoPromedioService
            foreach ($doc->movimientosInventario as $mov) {
                if ($mov->bodega_id) {
                    $this->cpp->reversarMovimiento(
                        $mov,
                        "Anulación de {$doc->numero}",
                    );
                }
            }

            $doc->update(['estado' => 'anulado']);
            $doc->delete();

            $this->auditLog->record(
                action:     'documento_ingreso.anulado',
                criticidad: AuditLogService::CRITICIDAD_WARNING,
                auditable:  $doc,
            );
        });

        return response()->json([
            'success' => true,
            'message' => "Documento {$doc->numero} anulado y movimientos de inventario revertidos.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper privado: armar asiento de compra
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye y persiste el asiento de la factura de compra.
     * Las líneas de débito (inventario/gasto + IVA) ya vienen pre-calculadas.
     * Este método solo añade las líneas de crédito (proveedor/caja y retenciones).
     */
    private function generarAsientoCompra(
        DocumentoIngreso          $doc,
        array                     $lineasDebito,
        float                     $retefuente,
        float                     $reteica,
        ?TipoDocumentoIngreso     $tipoDoc = null,
    ): Asiento {
        $lineas = $lineasDebito;

        // ── Crédito: proveedor / banco / caja según forma_pago ───────────────
        // credito          → 220505 Proveedores (Cuentas por pagar)
        // contado_efectivo → 110505 Caja (caja física)
        // contado_banco    → 111005 Bancos (transferencia/cheque/consignación)
        // contado (legacy) → 110505 Caja. Para pagos bancarios explícitos usar
        // contado_banco, así no se rompe la expectativa contable de "contado".
        if ($doc->forma_pago === 'credito') {
            $cuentaContraparte = $tipoDoc?->cuenta_proveedor_id
                ? \App\Models\Tenant\CuentaContable::findOrFail($tipoDoc->cuenta_proveedor_id)
                : $this->contabilizador->cuenta('compra.cuenta_proveedor');
        } elseif (in_array($doc->forma_pago, ['contado', 'contado_efectivo'], true)) {
            $cuentaContraparte = $this->contabilizador->cuenta('compra.cuenta_caja');
        } else {
            // contado_banco → Bancos
            $cuentaContraparte = $this->contabilizador->cuenta('compra.cuenta_banco');
        }

        // valor_total ya descontó las retenciones; el crédito al proveedor/caja
        // es lo que efectivamente se le paga. Las retenciones van a sus propias cuentas.
        $baseCredito = (float) $doc->valor_total;

        $lineas[] = [
            'cuenta_contable_id'   => $cuentaContraparte->id,
            'tercero_id'           => $doc->tercero_id,
            'debito'               => 0.0,
            'credito'              => $baseCredito,
            'descripcion'          => "Compra: {$doc->numero}",
            'documento_referencia' => $doc->numero_documento_proveedor ?? $doc->numero,
        ];

        // ── Crédito: retenciones ─────────────────────────────────────────────
        if ($retefuente > 0) {
            $lineas[] = [
                'cuenta_contable_id'   => $this->contabilizador->cuenta('compra.cuenta_retefuente')->id,
                'tercero_id'           => $doc->tercero_id,
                'debito'               => 0.0,
                'credito'              => $retefuente,
                'descripcion'          => "Retefuente: {$doc->numero}",
                'documento_referencia' => $doc->numero,
            ];
        }

        if ($reteica > 0) {
            $lineas[] = [
                'cuenta_contable_id'   => $this->contabilizador->cuenta('compra.cuenta_reteica')->id,
                'tercero_id'           => $doc->tercero_id,
                'debito'               => 0.0,
                'credito'              => $reteica,
                'descripcion'          => "ReteICA: {$doc->numero}",
                'documento_referencia' => $doc->numero,
            ];
        }

        return $this->contabilizador->contabilizar([
            'fecha'             => $doc->fecha->toDateString(),
            'tipo_comprobante'  => 'CO',
            'numero_documento'  => $doc->numero,
            'descripcion'       => "Factura de compra {$doc->numero} — {$doc->concepto}",
            'sucursal_id'       => $doc->sucursal_id,
            'origen'            => $doc,
            'created_by_id'     => auth()->id() ?? $doc->tercero_id,
            'lineas'            => $lineas,
        ]);
    }
}
