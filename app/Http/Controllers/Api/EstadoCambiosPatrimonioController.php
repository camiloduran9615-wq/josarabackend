<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reportes\EstadoCambiosPatrimonioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de Cambios en el Patrimonio (NIC 1).
 *
 * GET /reports/estado-cambios-patrimonio?año=N
 *
 * Devuelve cambios en el patrimonio durante el año fiscal: saldo inicial,
 * aumentos (créditos), disminuciones (débitos) y saldo final, agrupado
 * por categoría (Capital, Reservas, Resultados del Ejercicio, Acumulados, etc.).
 */
class EstadoCambiosPatrimonioController extends Controller
{
    public function __invoke(
        Request $request,
        EstadoCambiosPatrimonioService $service,
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
