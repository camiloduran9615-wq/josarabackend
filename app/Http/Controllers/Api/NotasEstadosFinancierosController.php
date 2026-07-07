<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reportes\NotasEstadosFinancierosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notas a los Estados Financieros (NIC 1.117).
 *
 * GET /reports/notas-estados-financieros?año=N
 *
 * Devuelve notas N4–N15 con desglose por cuenta y comparativo año anterior.
 */
class NotasEstadosFinancierosController extends Controller
{
    public function __invoke(
        Request $request,
        NotasEstadosFinancierosService $service,
    ): JsonResponse {
        $request->validate([
            'año' => 'required|integer|min:2020|max:2100',
        ]);

        $anio = (int) $request->input('año');
        $data = $service->generate($anio);

        $data['empresa'] = [
            'nombre' => \App\Models\Tenant\Config::get('company_name', ''),
            'nit'    => \App\Models\Tenant\Config::get('company_nit', ''),
        ];

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
