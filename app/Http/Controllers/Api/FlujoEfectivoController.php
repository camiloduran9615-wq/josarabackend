<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reportes\FlujoEfectivoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de Flujo de Efectivo (NIC 7) — método indirecto.
 *
 * GET /reports/flujo-efectivo?año=N
 */
class FlujoEfectivoController extends Controller
{
    public function __invoke(
        Request $request,
        FlujoEfectivoService $service,
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
