<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reportes\ExogenaCsvExporter;
use App\Services\Reportes\InformacionExogenaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Información Exógena DIAN — medios magnéticos anuales.
 *
 * Endpoints:
 *   GET /reports/exogena-1001?año=N   pagos a terceros + retenciones practicadas
 *   GET /reports/exogena-1003?año=N   retenciones que nos practicaron
 *   GET /reports/exogena-1005?año=N   IVA descontable por proveedor
 *   GET /reports/exogena-1006?año=N   IVA generado por cliente
 *   GET /reports/exogena-1007?año=N   ingresos recibidos por tercero
 */
class InformacionExogenaController extends Controller
{
    public function __construct(
        private readonly InformacionExogenaService $service,
        private readonly ExogenaCsvExporter $csvExporter,
    ) {}

    /**
     * FEAT-N: Descarga CSV pipe-delimited para subir al MUISCA DIAN.
     *
     * GET /reports/exogena-{formato}/csv?año=N
     */
    public function csv(Request $request, int $formato): Response
    {
        $request->validate([
            'año' => 'required|integer|min:2020|max:2100',
        ]);

        $anio = (int) $request->input('año');

        $data = match ($formato) {
            1001 => $this->service->formato1001($anio),
            1003 => $this->service->formato1003($anio),
            1005 => $this->service->formato1005($anio),
            1006 => $this->service->formato1006($anio),
            1007 => $this->service->formato1007($anio),
            1008 => $this->service->formato1008($anio),
            1009 => $this->service->formato1009($anio),
            default => abort(404, "Formato {$formato} no soportado."),
        };

        $csv = $this->csvExporter->exportar($formato, $data);
        $filename = sprintf('exogena-%d-%d.csv', $formato, $anio);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function formato1001(Request $request): JsonResponse
    {
        return $this->responder(1001, $request);
    }

    public function formato1003(Request $request): JsonResponse
    {
        return $this->responder(1003, $request);
    }

    public function formato1005(Request $request): JsonResponse
    {
        return $this->responder(1005, $request);
    }

    public function formato1006(Request $request): JsonResponse
    {
        return $this->responder(1006, $request);
    }

    public function formato1007(Request $request): JsonResponse
    {
        return $this->responder(1007, $request);
    }

    public function formato1008(Request $request): JsonResponse
    {
        return $this->responder(1008, $request);
    }

    public function formato1009(Request $request): JsonResponse
    {
        return $this->responder(1009, $request);
    }

    private function responder(int $formato, Request $request): JsonResponse
    {
        $request->validate([
            'año' => 'required|integer|min:2020|max:2100',
        ]);

        $anio = (int) $request->input('año');

        $data = match ($formato) {
            1001 => $this->service->formato1001($anio),
            1003 => $this->service->formato1003($anio),
            1005 => $this->service->formato1005($anio),
            1006 => $this->service->formato1006($anio),
            1007 => $this->service->formato1007($anio),
            1008 => $this->service->formato1008($anio),
            1009 => $this->service->formato1009($anio),
        };

        $data['formato'] = $formato;
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
