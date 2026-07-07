<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ReciboCaja;
use App\Models\Tenant\Factura;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReciboCajaController extends Controller
{
    public function __construct(
        protected ContabilizadorService $contabilizador,
    ) {}

    public function index(): JsonResponse
    {
        $recibos = ReciboCaja::with('tercero')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $recibos]);
    }

    /**
     * GET /recibos-caja/cartera/{terceroId}
     * Devuelve las facturas validadas del cliente con saldo pendiente > 0.
     * Calcula lo ya abonado sumando los `facturas_aplicadas` de recibos previos.
     */
    public function cartera(string $terceroId): JsonResponse
    {
        $facturas = Factura::where('tercero_id', $terceroId)
            ->where('estado', 'validado')
            ->where('tipo_documento', 'FV')
            ->orderBy('fecha_emision', 'asc')
            ->get();

        // Sumar abonos previos por factura desde recibos_caja.facturas_aplicadas
        $recibosPrevios = ReciboCaja::where('tercero_id', $terceroId)
            ->where('estado', 'registrado')
            ->whereNotNull('facturas_aplicadas')
            ->get(['facturas_aplicadas']);

        $abonadoPorFactura = [];
        foreach ($recibosPrevios as $r) {
            $aplicaciones = is_array($r->facturas_aplicadas) ? $r->facturas_aplicadas : [];
            foreach ($aplicaciones as $apl) {
                $fid = $apl['factura_id'] ?? null;
                $val = (float) ($apl['valor_aplicado'] ?? 0);
                if ($fid && $val > 0) {
                    $abonadoPorFactura[$fid] = ($abonadoPorFactura[$fid] ?? 0) + $val;
                }
            }
        }

        $resultado = $facturas->map(function (Factura $f) use ($abonadoPorFactura) {
            $total    = (float) $f->valor_total;
            $abonado  = (float) ($abonadoPorFactura[$f->id] ?? 0);
            $saldo    = round($total - $abonado, 2);
            return [
                'id'              => $f->id,
                'numero_completo' => $f->numero_completo ?: $f->reference_code,
                'fecha_emision'   => $f->fecha_emision,
                'payment_due_date'=> $f->payment_due_date,
                'valor_total'     => $total,
                'valor_abonado'   => $abonado,
                'saldo'           => $saldo,
                'payment_form'    => $f->payment_form,
                'estado_pago'     => $saldo <= 0 ? 'pagada' : ($abonado > 0 ? 'parcial' : 'pendiente'),
            ];
        })->filter(fn ($f) => $f['saldo'] > 0.01)
          ->values();

        return response()->json([
            'success' => true,
            'data'    => $resultado,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'centro_costo_id'   => 'nullable|uuid|exists:centros_costo,id',
            'tercero_id'        => 'required|exists:terceros,id',
            'fecha'             => 'required|date',
            'valor_recibido'    => 'required|numeric|min:0.01',
            'concepto'          => 'required|string|max:500',
            'forma_pago'        => 'required|in:efectivo,cheque,transferencia,tarjeta_debito,tarjeta_credito,consignacion,otro',
            'banco'             => 'nullable|string',
            'numero_cheque'     => 'nullable|string',
            'referencia_pago'   => 'nullable|string',
            'facturas_aplicadas'=> 'nullable|array',
            'observaciones'     => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request): JsonResponse {
            $numero = 'RC-' . str_pad((string)(ReciboCaja::count() + 1), 6, '0', STR_PAD_LEFT);

            $recibo = ReciboCaja::create([
                'centro_costo_id'    => $request->centro_costo_id,
                'tercero_id'         => $request->tercero_id,
                'numero'             => $numero,
                'fecha'              => $request->fecha,
                'valor_recibido'     => $request->valor_recibido,
                'concepto'           => $request->concepto,
                'forma_pago'         => $request->forma_pago,
                'banco'              => $request->banco,
                'numero_cheque'      => $request->numero_cheque,
                'referencia_pago'    => $request->referencia_pago,
                'facturas_aplicadas' => $request->facturas_aplicadas,
                'observaciones'      => $request->observaciones,
                'estado'             => 'registrado',
            ]);

            // ── Asiento: DR Caja|Bancos / CR Clientes CxC ───────────────────
            // Si la forma_pago es efectivo => 110505 Caja.
            // Cualquier otra (transferencia, cheque, consignación, tarjetas) => 111005 Bancos.
            try {
                $esEfectivo = $request->forma_pago === 'efectivo';
                // Claves nuevas (recibo_caja.*) tienen prioridad; legacy (recibo.cuenta_caja) como fallback.
                $claveDebito = $esEfectivo ? 'recibo_caja.cuenta_caja' : 'recibo_caja.cuenta_banco';
                try {
                    $cuentaDebito = $this->contabilizador->cuenta($claveDebito);
                } catch (ParametrizacionFaltanteException $e) {
                    // Fallback a clave legacy si la nueva no está parametrizada
                    $cuentaDebito = $this->contabilizador->cuenta('recibo.cuenta_caja');
                }
                $cuentaCxc = $this->contabilizador->cuenta('recibo.cuenta_cxc');
                $valor     = round((float) $request->valor_recibido, 2);

                $this->contabilizador->contabilizar([
                    'fecha'            => $request->fecha,
                    'tipo_comprobante' => 'RC',
                    'descripcion'      => "Recibo de caja {$recibo->numero} — {$request->concepto}",
                    'origen'           => $recibo,
                    'created_by_id'    => auth()->id() ?? $request->tercero_id,
                    'lineas'           => [
                        [
                            'cuenta_contable_id' => $cuentaDebito->id,
                            'debito'             => $valor,
                            'credito'            => 0.0,
                            'descripcion'        => "Cobro {$recibo->numero} ({$request->forma_pago})",
                            'tercero_id'         => $request->tercero_id,
                        ],
                        [
                            'cuenta_contable_id' => $cuentaCxc->id,
                            'debito'             => 0.0,
                            'credito'            => $valor,
                            'descripcion'        => "CxC cobrada {$recibo->numero}",
                            'tercero_id'         => $request->tercero_id,
                        ],
                    ],
                ]);
            } catch (ParametrizacionFaltanteException $e) {
                Log::warning('ReciboCajaController: parametrización contable incompleta', [
                    'recibo_id' => $recibo->id,
                    'error'     => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::error('ReciboCajaController: error generando asiento', [
                    'recibo_id' => $recibo->id,
                    'error'     => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Recibo de caja registrado.',
                'data'    => $recibo->load('tercero'),
            ], 201);
        });
    }

    public function show(string $id): JsonResponse
    {
        $recibo = ReciboCaja::with('tercero')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $recibo]);
    }

    public function destroy(string $id): JsonResponse
    {
        $recibo = ReciboCaja::withTrashed()->findOrFail($id);

        if ($recibo->estado === 'anulado' || $recibo->trashed()) {
            return response()->json(['success' => false, 'message' => 'El recibo ya está anulado.'], 422);
        }

        $recibo->update(['estado' => 'anulado']);
        $recibo->delete();

        return response()->json(['success' => true, 'message' => 'Recibo anulado.']);
    }
}
