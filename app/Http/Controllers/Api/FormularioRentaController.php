<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reportes\FormularioRentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Formulario 110 DIAN — Declaración de Renta y Complementario.
 *
 * GET /reports/formulario-110?año=N[&tarifa=0.35]
 *
 * Personas jurídicas y asimiladas. Tarifa por defecto 35%
 * (Ley 2277/2022). Devuelve los renglones prellenados desde
 * la contabilidad del año.
 */
class FormularioRentaController extends Controller
{
    public function __invoke(
        Request $request,
        FormularioRentaService $service,
    ): JsonResponse {
        $request->validate([
            'año'    => 'required|integer|min:2020|max:2100',
            'tarifa' => 'nullable|numeric|min:0|max:1',
        ]);

        $anio   = (int)   $request->input('año');
        $tarifa = $request->filled('tarifa') ? (float) $request->input('tarifa') : null;

        $data = $service->generate($anio, $tarifa);

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
