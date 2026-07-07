<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reportes\BalanceComprobacionService;
use App\Services\Reportes\BalanceGeneralService;
use App\Services\Reportes\EstadoCambiosPatrimonioService;
use App\Services\Reportes\EstadoResultadosService;
use App\Services\Reportes\EstadosFinancierosCsvExporter;
use App\Services\Reportes\FlujoEfectivoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FEAT-O: Descarga CSV de los Estados Financieros NIIF.
 *
 * GET /reports/csv/balance-general?fecha_corte=YYYY-MM-DD
 * GET /reports/csv/estado-resultados?desde=&hasta=
 * GET /reports/csv/estado-cambios-patrimonio?año=N
 * GET /reports/csv/flujo-efectivo?año=N
 * GET /reports/csv/balance-comprobacion?periodo_id=
 */
class EstadosFinancierosCsvController extends Controller
{
    public function __construct(
        private readonly EstadosFinancierosCsvExporter $exporter,
        private readonly BalanceGeneralService $bgService,
        private readonly EstadoResultadosService $erService,
        private readonly EstadoCambiosPatrimonioService $ecpService,
        private readonly FlujoEfectivoService $efeService,
        private readonly BalanceComprobacionService $bcService,
    ) {}

    public function balanceGeneral(Request $request): Response
    {
        $request->validate([
            'fecha_corte' => 'required|date',
            'comparativo' => 'nullable|boolean',
        ]);

        $dto = $this->bgService->generate(
            $request->input('fecha_corte'),
            $request->boolean('comparativo'),
        );
        $data = $this->dtoToArray($dto);

        $csv = $this->exporter->balanceGeneral($data);
        return $this->responder($csv, sprintf('balance-general-%s.csv', $request->input('fecha_corte')));
    }

    public function estadoResultados(Request $request): Response
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'comparativo' => 'nullable|boolean',
        ]);

        $dto = $this->erService->generate(
            $request->input('desde'),
            $request->input('hasta'),
            $request->boolean('comparativo'),
        );
        $data = $this->dtoToArray($dto);

        $csv = $this->exporter->estadoResultados($data);
        return $this->responder(
            $csv,
            sprintf('estado-resultados-%s-a-%s.csv', $request->input('desde'), $request->input('hasta')),
        );
    }

    public function estadoCambiosPatrimonio(Request $request): Response
    {
        $request->validate(['año' => 'required|integer|min:2020|max:2100']);

        $data = $this->ecpService->generate((int) $request->input('año'));
        $csv  = $this->exporter->estadoCambiosPatrimonio($data);

        return $this->responder($csv, sprintf('cambios-patrimonio-%d.csv', (int) $request->input('año')));
    }

    public function flujoEfectivo(Request $request): Response
    {
        $request->validate(['año' => 'required|integer|min:2020|max:2100']);

        $data = $this->efeService->generate((int) $request->input('año'));
        $csv  = $this->exporter->flujoEfectivo($data);

        return $this->responder($csv, sprintf('flujo-efectivo-%d.csv', (int) $request->input('año')));
    }

    public function balanceComprobacion(Request $request): Response
    {
        $request->validate([
            'periodo_id' => 'required|uuid',
            'nivel'      => 'nullable|integer|min:1|max:3',
        ]);

        $dto = $this->bcService->generate(
            $request->input('periodo_id'),
            (int) $request->input('nivel', 1),
        );
        $data = $this->dtoToArray($dto);

        $csv = $this->exporter->balanceComprobacion($data);
        return $this->responder($csv, 'balance-comprobacion.csv');
    }

    /**
     * Convierte cualquier DTO (o array) a array asociativo. Usa json_decode
     * para serializar las propiedades públicas/anidadas sin importar la
     * forma exacta del DTO (que varía entre servicios).
     */
    private function dtoToArray(mixed $dto): array
    {
        if (is_array($dto)) {
            return $dto;
        }
        return json_decode(json_encode($dto), true) ?? [];
    }

    private function responder(string $csv, string $filename): Response
    {
        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}
