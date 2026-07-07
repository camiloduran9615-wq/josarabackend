<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AjusteCartera;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AjusteCarteraController extends Controller
{
    public function index(): JsonResponse
    {
        $ajustes = AjusteCartera::with(['tercero', 'factura', 'cuentaDebito', 'cuentaCredito'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $ajustes]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'centro_costo_id'  => 'nullable|uuid|exists:centros_costo,id',
            'tercero_id'       => 'required|exists:terceros,id',
            'factura_id'       => 'nullable|exists:facturas,id',
            'cuenta_debito_id' => 'nullable|exists:cuentas_contables,id',
            'cuenta_credito_id'=> 'nullable|exists:cuentas_contables,id',
            'fecha'            => 'required|date',
            'tipo'             => 'required|in:castigo_cartera,descuento_pronto_pago,provision_cartera,recuperacion_cartera,abono_parcial,diferencia_cambio,otro',
            'concepto'         => 'required|string|max:500',
            'valor'            => 'required|numeric|min:0.01',
            'observaciones'    => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request): JsonResponse {
            $numero = 'AJC-' . str_pad((string)(AjusteCartera::count() + 1), 6, '0', STR_PAD_LEFT);

            $ajuste = AjusteCartera::create([
                'centro_costo_id'   => $request->centro_costo_id,
                'tercero_id'        => $request->tercero_id,
                'factura_id'        => $request->factura_id,
                'cuenta_debito_id'  => $request->cuenta_debito_id,
                'cuenta_credito_id' => $request->cuenta_credito_id,
                'numero'            => $numero,
                'fecha'             => $request->fecha,
                'tipo'              => $request->tipo,
                'concepto'          => $request->concepto,
                'valor'             => $request->valor,
                'estado'            => 'aplicado',
                'observaciones'     => $request->observaciones,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ajuste de cartera registrado.',
                'data'    => $ajuste->load('tercero', 'factura'),
            ], 201);
        });
    }

    public function show(string $id): JsonResponse
    {
        $ajuste = AjusteCartera::with(['tercero', 'factura', 'cuentaDebito', 'cuentaCredito'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $ajuste]);
    }

    public function destroy(string $id): JsonResponse
    {
        $ajuste = AjusteCartera::findOrFail($id);

        if ($ajuste->estado === 'anulado') {
            return response()->json(['success' => false, 'message' => 'Ya está anulado.'], 422);
        }

        $ajuste->update(['estado' => 'anulado']);
        $ajuste->delete();

        return response()->json(['success' => true, 'message' => 'Ajuste anulado.']);
    }
}
