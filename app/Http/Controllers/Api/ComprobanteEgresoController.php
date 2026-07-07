<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\ComprobanteEgreso;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Services\AuditLogService;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Comprobantes de Egreso — pagos realizados a proveedores.
 *
 * Asiento generado:
 *   DÉBITO  cuenta_debito_id   (Cuentas por Pagar del proveedor, clase 22)
 *   CRÉDITO cuenta_credito_id  (Banco o Caja desde donde sale el dinero, clase 11)
 *
 * Equivalente SIIGO: Proveedores → Comprobantes de Egreso
 */
class ComprobanteEgresoController extends Controller
{
    public function __construct(
        private readonly ContabilizadorService $contabilizador,
        private readonly AuditLogService       $auditLog,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /comprobantes-egreso
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = ComprobanteEgreso::with(['tercero', 'cuentaDebito', 'cuentaCredito'])
            ->orderBy('fecha', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('tercero_id')) $query->where('tercero_id', $request->tercero_id);
        if ($request->filled('estado'))     $query->where('estado', $request->estado);
        if ($request->filled('desde'))      $query->whereDate('fecha', '>=', $request->desde);
        if ($request->filled('hasta'))      $query->whereDate('fecha', '<=', $request->hasta);

        $comprobantes = $query->paginate((int) ($request->per_page ?? 25));

        return response()->json([
            'success' => true,
            'data'    => $comprobantes->items(),
            'meta'    => [
                'total'        => $comprobantes->total(),
                'per_page'     => $comprobantes->perPage(),
                'current_page' => $comprobantes->currentPage(),
                'last_page'    => $comprobantes->lastPage(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /comprobantes-egreso
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'centro_costo_id'    => ['nullable', 'uuid', 'exists:centros_costo,id'],
            'tercero_id'         => ['required', 'uuid', 'exists:terceros,id'],
            'fecha'              => ['required', 'date'],
            'concepto'           => ['required', 'string', 'max:500'],
            'forma_pago'         => ['required', 'in:efectivo,transferencia,cheque,consignacion,otro'],
            'banco'              => ['nullable', 'string', 'max:100'],
            'numero_cheque'      => ['nullable', 'string', 'max:50'],
            'referencia_pago'    => ['nullable', 'string', 'max:100'],
            // Cuenta DÉBITO: lo que se cancela (normalmente Cuentas por Pagar, clase 22)
            'cuenta_debito_id'   => ['required', 'uuid', 'exists:cuentas_contables,id'],
            // Cuenta CRÉDITO: de donde sale el dinero (Banco o Caja, clase 11)
            'cuenta_credito_id'  => ['required', 'uuid', 'exists:cuentas_contables,id'],
            'valor_pagado'       => ['required', 'numeric', 'min:0.01'],
            'facturas_aplicadas' => ['nullable', 'array'],
            'facturas_aplicadas.*'=> ['uuid'],
            'observaciones'      => ['nullable', 'string', 'max:1000'],
        ]);

        // ── Verificar periodo abierto ────────────────────────────────────────
        $periodo = PeriodoContable::actual(now());
        if ($periodo === null || $periodo->estado !== 'abierto') {
            return response()->json([
                'success' => false,
                'message' => 'No hay periodo contable abierto para hoy. Crea o abre un período antes de registrar comprobantes.',
            ], 422);
        }

        return DB::transaction(function () use ($validated): JsonResponse {

            // ── Consecutivo ─────────────────────────────────────────────────
            $numero = 'CE-' . str_pad(
                (string) (ComprobanteEgreso::withTrashed()->count() + 1),
                6, '0', STR_PAD_LEFT
            );

            /** @var ComprobanteEgreso $comp */
            $comp = ComprobanteEgreso::create([
                'centro_costo_id'    => $validated['centro_costo_id'] ?? null,
                'tercero_id'         => $validated['tercero_id'],
                'numero'             => $numero,
                'fecha'              => $validated['fecha'],
                'concepto'           => $validated['concepto'],
                'forma_pago'         => $validated['forma_pago'],
                'banco'              => $validated['banco'] ?? null,
                'numero_cheque'      => $validated['numero_cheque'] ?? null,
                'referencia_pago'    => $validated['referencia_pago'] ?? null,
                'cuenta_debito_id'   => $validated['cuenta_debito_id'],
                'cuenta_credito_id'  => $validated['cuenta_credito_id'],
                'valor_pagado'       => $validated['valor_pagado'],
                'facturas_aplicadas' => $validated['facturas_aplicadas'] ?? null,
                'observaciones'      => $validated['observaciones'] ?? null,
                'estado'             => 'registrado',
            ]);

            // ── Generar asiento contable ─────────────────────────────────────
            try {
                $asiento = $this->contabilizador->contabilizar([
                    'fecha'            => $comp->fecha->toDateString(),
                    'tipo_comprobante' => 'CE',
                    'numero_documento' => $comp->numero,
                    'descripcion'      => "Pago a proveedor {$comp->numero} — {$comp->concepto}",
                    'origen'           => $comp,
                    'created_by_id'    => auth()->id(),
                    'lineas'           => [
                        // DÉBITO: cancela la deuda con el proveedor
                        [
                            'cuenta_contable_id'   => $validated['cuenta_debito_id'],
                            'tercero_id'           => $validated['tercero_id'],
                            'debito'               => (float) $validated['valor_pagado'],
                            'credito'              => 0.0,
                            'descripcion'          => $comp->concepto,
                            'documento_referencia' => $comp->numero,
                        ],
                        // CRÉDITO: sale el dinero del banco / caja
                        [
                            'cuenta_contable_id'   => $validated['cuenta_credito_id'],
                            'tercero_id'           => null,
                            'debito'               => 0.0,
                            'credito'              => (float) $validated['valor_pagado'],
                            'descripcion'          => "Pago: {$comp->numero}",
                            'documento_referencia' => $validated['referencia_pago'] ?? $comp->numero,
                        ],
                    ],
                ]);

                $comp->update(['asiento_id' => $asiento->id]);

            } catch (ParametrizacionFaltanteException $e) {
                logger()->warning('ComprobanteEgreso: asiento fallido', [
                    'comprobante' => $comp->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            // ── Auditoría ────────────────────────────────────────────────────
            $this->auditLog->record(
                action:     'comprobante_egreso.creado',
                criticidad: AuditLogService::CRITICIDAD_INFO,
                auditable:  $comp,
                newValues:  [
                    'numero'      => $comp->numero,
                    'valor'       => $comp->valor_pagado,
                    'forma_pago'  => $comp->forma_pago,
                    'tercero_id'  => $comp->tercero_id,
                ],
            );

            return response()->json([
                'success' => true,
                'message' => "Comprobante {$comp->numero} registrado correctamente.",
                'data'    => $comp->load(['tercero', 'cuentaDebito', 'cuentaCredito', 'asiento']),
            ], 201);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /comprobantes-egreso/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(string $id): JsonResponse
    {
        $comp = ComprobanteEgreso::with([
            'tercero',
            'cuentaDebito',
            'cuentaCredito',
            'asiento.lineas.cuenta',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $comp]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /comprobantes-egreso/{id} — Anulación
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(string $id): JsonResponse
    {
        $comp = ComprobanteEgreso::findOrFail($id);

        if ($comp->estado === 'anulado') {
            return response()->json([
                'success' => false,
                'message' => 'El comprobante ya está anulado.',
            ], 422);
        }

        DB::transaction(function () use ($comp): void {
            $comp->update(['estado' => 'anulado']);
            $comp->delete();

            $this->auditLog->record(
                action:     'comprobante_egreso.anulado',
                criticidad: AuditLogService::CRITICIDAD_WARNING,
                auditable:  $comp,
            );
        });

        return response()->json([
            'success' => true,
            'message' => "Comprobante {$comp->numero} anulado.",
        ]);
    }
}
