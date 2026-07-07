<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\FacturaRetencion;
use App\Models\Tenant\Tercero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Reporte detallado de retenciones PRACTICADAS A LA EMPRESA POR CLIENTES
     * (anticipos a favor — débito en 1355xx).
     *
     * IMPORTANTE: NO filtramos por estado='validado'. Las retenciones existen
     * desde que se emite la factura, independiente de si Factus la validó
     * o no. Filtrar por validado dejaría fuera tenants sin integración DIAN.
     *
     * Usa fecha_emision como referencia temporal (siempre existe), no
     * fecha_validacion (solo existe si Factus validó).
     */
    public function withholdings(Request $request)
    {
        $request->validate([
            'start_date'   => 'nullable|date',
            'end_date'     => 'nullable|date',
            'incluir_anuladas' => 'nullable|boolean',
        ]);

        $incluirAnuladas = $request->boolean('incluir_anuladas', false);

        $query = FacturaRetencion::with(['factura.tercero']);

        $query->whereHas('factura', function ($q) use ($request, $incluirAnuladas): void {
            // Filtramos por todos los estados EXCEPTO 'anulada' (por defecto).
            // borrador/error/validado todos representan retenciones reales.
            if (! $incluirAnuladas) {
                $q->where('estado', '!=', 'anulado');
            }
            if ($request->start_date) {
                $q->whereDate('fecha_emision', '>=', $request->start_date);
            }
            if ($request->end_date) {
                $q->whereDate('fecha_emision', '<=', $request->end_date);
            }
        });

        $retenciones = $query->get()->map(function ($ret) {
            $fac = $ret->factura;
            // fecha_emision viene como string del modelo (no casteada).
            // La normalizamos con Carbon::parse para soportar ambos formatos.
            $fechaFmt = $fac->fecha_emision !== null
                ? \Carbon\Carbon::parse((string) $fac->fecha_emision)->format('Y-m-d')
                : $fac->created_at->format('Y-m-d');

            return [
                'id'            => $ret->id,
                'codigo'        => $ret->codigo,
                'nombre'        => $ret->nombre,
                'tasa'          => $ret->tasa,
                'valor'         => $ret->valor,
                'base'          => $ret->base,
                'factura'       => $fac->numero_completo ?: $fac->reference_code,
                'estado'        => $fac->estado,
                'fecha'         => $fechaFmt,
                'cliente'       => $fac->tercero->razon_social,
                'nit'           => $fac->tercero->identificacion,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $retenciones,
            'total_retenido' => round($retenciones->sum('valor'), 2),
        ]);
    }

    /**
     * Datos para el Certificado de Retención por tercero (CLIENTE que nos
     * practicó retenciones — anticipos a nuestro favor).
     *
     * Por el mismo motivo que withholdings: NO filtramos por estado='validado'.
     */
    public function certificate(Request $request, $terceroId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $tercero = Tercero::findOrFail($terceroId);

        $resumen = FacturaRetencion::whereHas('factura', function ($q) use ($terceroId, $request) {
                $q->where('tercero_id', $terceroId)
                  ->where('estado', '!=', 'anulado')
                  ->whereDate('fecha_emision', '>=', $request->start_date)
                  ->whereDate('fecha_emision', '<=', $request->end_date);
            })
            ->select(
                'codigo',
                'nombre',
                'tasa',
                DB::raw('SUM(valor) as total_retenido'),
                DB::raw('SUM(base) as total_base')
            )
            ->groupBy('codigo', 'nombre', 'tasa')
            ->get();

        return response()->json([
            'success' => true,
            'tercero' => $tercero,
            'periodo' => [
                'desde' => $request->start_date,
                'hasta' => $request->end_date,
            ],
            'retenciones' => $resumen,
            'empresa' => [
                'nombre' => \App\Models\Tenant\Config::get('company_name', 'Mi Empresa S.A.S'),
                'nit' => \App\Models\Tenant\Config::get('company_nit', '900.000.000-1'),
                'direccion' => \App\Models\Tenant\Config::get('company_address', 'Calle Principal #123'),
                'ciudad' => \App\Models\Tenant\Config::get('company_city', 'Bucaramanga, Santander'),
                'telefono' => \App\Models\Tenant\Config::get('company_phone', '6071234567'),
            ]
        ]);
    }

    /**
     * FEAT-B: Reporte de RETENCIONES PRACTICADAS A PROVEEDORES.
     *
     * Lista los documentos de ingreso (facturas de compra, cuentas de cobro,
     * gastos) en los que LA EMPRESA retuvo a sus proveedores. Estos valores
     * son pasivos a favor de la DIAN/Municipio (cuentas 2365xx y 2368xx) que
     * se reportan en el Formulario 350 (mensual) y se certifican al proveedor.
     *
     * Filtros opcionales:
     *   start_date / end_date  por fecha del documento
     *   tercero_id             un solo proveedor
     *   tipo_retencion         retefuente | reteica | reteiva (filtra > 0 en ese campo)
     */
    public function retefuentePracticada(Request $request)
    {
        $request->validate([
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'tercero_id'     => 'nullable|exists:terceros,id',
            'tipo_retencion' => 'nullable|in:retefuente,reteica,reteiva',
        ]);

        $query = DocumentoIngreso::query()
            ->with(['tercero'])
            ->where('estado', '!=', 'anulado')
            // Solo documentos con al menos una retención > 0
            ->where(function ($q): void {
                $q->where('valor_retefuente', '>', 0)
                  ->orWhere('valor_reteica',  '>', 0)
                  ->orWhere('valor_reteiva',  '>', 0);
            });

        if ($request->start_date) {
            $query->whereDate('fecha', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('fecha', '<=', $request->end_date);
        }
        if ($request->filled('tercero_id')) {
            $query->where('tercero_id', $request->tercero_id);
        }
        if ($request->filled('tipo_retencion')) {
            $columna = 'valor_' . $request->tipo_retencion;
            $query->where($columna, '>', 0);
        }

        $documentos = $query->orderBy('fecha', 'desc')->get();

        // Detalle por documento (uno por cada tipo de retención > 0)
        $detalle = [];
        $totales = ['retefuente' => 0.0, 'reteica' => 0.0, 'reteiva' => 0.0];

        foreach ($documentos as $doc) {
            $fechaFmt = $doc->fecha !== null
                ? \Carbon\Carbon::parse((string) $doc->fecha)->format('Y-m-d')
                : null;

            foreach (['retefuente', 'reteica', 'reteiva'] as $tipo) {
                $valor = (float) $doc->{'valor_' . $tipo};
                if ($valor <= 0) {
                    continue;
                }
                $totales[$tipo] += $valor;
                $detalle[] = [
                    'documento_id'      => $doc->id,
                    'numero'            => $doc->numero,
                    'numero_proveedor'  => $doc->numero_documento_proveedor,
                    'fecha'             => $fechaFmt,
                    'estado'            => $doc->estado,
                    'tipo_retencion'    => $tipo,
                    'valor'             => $valor,
                    'base'              => (float) $doc->valor_bruto,
                    'proveedor_id'      => $doc->tercero?->id,
                    'proveedor'         => $doc->tercero?->razon_social,
                    'nit_proveedor'     => $doc->tercero?->identificacion,
                ];
            }
        }

        return response()->json([
            'success'      => true,
            'data'         => $detalle,
            'totales'      => [
                'retefuente'      => round($totales['retefuente'], 2),
                'reteica'         => round($totales['reteica'], 2),
                'reteiva'         => round($totales['reteiva'], 2),
                'total_retenido'  => round(array_sum($totales), 2),
            ],
            'count_documentos' => $documentos->count(),
        ]);
    }

    /**
     * FEAT-C: Reporte para Formulario 300 IVA bimestral (DIAN).
     *
     * Agrega:
     *  - Ingresos gravados por tarifa (19%, 5%, 0%/exentos)
     *  - IVA generado por tarifa (cuentas 240805, 240802; el sistema usa
     *    factura_items.porcentaje_iva como fuente de verdad)
     *  - IVA descontable (suma documentos_ingreso.valor_iva)
     *  - Saldo a pagar (si > 0) o a favor (si < 0)
     *
     * Bimestres DIAN:
     *  1 = enero-febrero    | 2 = marzo-abril    | 3 = mayo-junio
     *  4 = julio-agosto     | 5 = sept-octubre   | 6 = nov-diciembre
     *
     * Filtros:
     *  año (required) — año fiscal
     *  bimestre (required) — 1..6
     */
    public function ivaBimestral(Request $request)
    {
        $request->validate([
            'año'      => 'required|integer|min:2020|max:2100',
            'bimestre' => 'required|integer|min:1|max:6',
        ]);

        $anio = (int) $request->input('año');
        $bim  = (int) $request->input('bimestre');

        $mesInicio = (($bim - 1) * 2) + 1;
        $desde = sprintf('%04d-%02d-01', $anio, $mesInicio);
        $hasta = (new \DateTimeImmutable($desde))->modify('+2 months -1 day')->format('Y-m-d');

        // ── Ingresos gravados por tarifa (desde factura_items) ──────────────
        $ingresosRaw = DB::table('factura_items as fi')
            ->join('facturas as f', 'f.id', '=', 'fi.factura_id')
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->where('f.estado', '!=', 'anulado')
            ->selectRaw('
                fi.porcentaje_iva,
                SUM(fi.cantidad * fi.precio_unitario) AS base_total,
                SUM(COALESCE(fi.valor_iva, 0)) AS iva_total
            ')
            ->groupBy('fi.porcentaje_iva')
            ->get();

        // Plantilla con las 4 tarifas DIAN típicas: 0 (exento), 5, 19, otros
        $ingresos = [
            'tarifa_19'  => ['base' => 0.0, 'iva' => 0.0],
            'tarifa_5'   => ['base' => 0.0, 'iva' => 0.0],
            'tarifa_0'   => ['base' => 0.0, 'iva' => 0.0],
            'tarifa_otros' => ['base' => 0.0, 'iva' => 0.0],
        ];

        foreach ($ingresosRaw as $row) {
            $tarifa = (int) $row->porcentaje_iva;
            $key = match ($tarifa) {
                19 => 'tarifa_19',
                5  => 'tarifa_5',
                0  => 'tarifa_0',
                default => 'tarifa_otros',
            };
            $ingresos[$key]['base'] = round((float) $row->base_total, 2);
            $ingresos[$key]['iva']  = round((float) $row->iva_total, 2);
        }

        $baseTotal = array_sum(array_column($ingresos, 'base'));
        $ivaGenerado = array_sum(array_column($ingresos, 'iva'));

        // ── IVA descontable (de compras) ────────────────────────────────────
        $comprasRow = DB::table('documentos_ingreso')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '!=', 'anulado')
            ->selectRaw('
                SUM(valor_bruto) AS base_compras,
                SUM(valor_iva)   AS iva_descontable,
                COUNT(*)         AS num_compras
            ')
            ->first();

        $ivaDescontable = round((float) ($comprasRow->iva_descontable ?? 0), 2);
        $baseCompras    = round((float) ($comprasRow->base_compras    ?? 0), 2);

        // ── Saldo ───────────────────────────────────────────────────────────
        $saldo = round($ivaGenerado - $ivaDescontable, 2);

        return response()->json([
            'success'   => true,
            'periodo'   => [
                'año'      => $anio,
                'bimestre' => $bim,
                'desde'    => $desde,
                'hasta'    => $hasta,
                'nombre'   => sprintf('Bimestre %d de %d (%s a %s)', $bim, $anio, $desde, $hasta),
            ],
            'ingresos'  => [
                'por_tarifa'   => $ingresos,
                'base_total'   => round($baseTotal, 2),
                'iva_generado' => round($ivaGenerado, 2),
            ],
            'compras'   => [
                'base_compras'   => $baseCompras,
                'iva_descontable'=> $ivaDescontable,
                'num_compras'    => (int) ($comprasRow->num_compras ?? 0),
            ],
            'balance'   => [
                'iva_generado'    => round($ivaGenerado, 2),
                'iva_descontable' => $ivaDescontable,
                'saldo'           => $saldo,
                'saldo_a_pagar'   => $saldo > 0 ? $saldo : 0.0,
                'saldo_a_favor'   => $saldo < 0 ? abs($saldo) : 0.0,
            ],
            'empresa'   => [
                'nombre' => \App\Models\Tenant\Config::get('company_name', ''),
                'nit'    => \App\Models\Tenant\Config::get('company_nit', ''),
            ],
        ]);
    }

    /**
     * FEAT-D: Reporte para Formulario 350 — RETENCIONES MENSUAL.
     *
     * Consolida las retenciones que la empresa practicó a sus proveedores
     * en el mes (a consignar a la DIAN). Agrupa por concepto:
     *
     *   - Retefuente compras (2.5% / 3.5%)
     *   - Retefuente servicios (4% / 6%)
     *   - Retefuente honorarios (10% / 11%)
     *   - Retefuente arrendamientos (3.5%)
     *   - ReteICA (variable por municipio)
     *   - ReteIVA (15% sobre IVA discriminado)
     *
     * NOTA: Por simplicidad, agrupa por TIPO de retención (retefuente / reteica
     * / reteiva). Para discriminar por concepto específico (compras vs servicios
     * vs honorarios) se requeriría que el cliente envíe el código DIAN del
     * concepto al crear la factura de compra — feature futuro.
     *
     * Filtros:
     *   año (required) / mes (required) 1..12
     */
    public function retencionesMensual(Request $request)
    {
        $request->validate([
            'año' => 'required|integer|min:2020|max:2100',
            'mes' => 'required|integer|min:1|max:12',
        ]);

        $anio = (int) $request->input('año');
        $mes  = (int) $request->input('mes');

        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = (new \DateTimeImmutable($desde))->modify('+1 month -1 day')->format('Y-m-d');

        // Consolidar las tres retenciones mensuales
        $resumen = DB::table('documentos_ingreso')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '!=', 'anulado')
            ->selectRaw('
                COUNT(*)                AS num_documentos,
                SUM(valor_bruto)        AS total_base,
                SUM(valor_iva)          AS total_iva_compras,
                SUM(valor_retefuente)   AS retefuente,
                SUM(valor_reteica)      AS reteica,
                SUM(valor_reteiva)      AS reteiva
            ')
            ->first();

        $retefuente = round((float) ($resumen->retefuente ?? 0), 2);
        $reteica    = round((float) ($resumen->reteica    ?? 0), 2);
        $reteiva    = round((float) ($resumen->reteiva    ?? 0), 2);

        // Detalle por proveedor (top 50 por monto retenido)
        $detalleProveedor = DB::table('documentos_ingreso as di')
            ->join('terceros as t', 't.id', '=', 'di.tercero_id')
            ->whereBetween('di.fecha', [$desde, $hasta])
            ->where('di.estado', '!=', 'anulado')
            ->whereRaw('(di.valor_retefuente > 0 OR di.valor_reteica > 0 OR di.valor_reteiva > 0)')
            ->selectRaw('
                t.id                    AS tercero_id,
                t.razon_social          AS proveedor,
                t.identificacion        AS nit,
                COUNT(*)                AS num_documentos,
                SUM(di.valor_bruto)     AS base,
                SUM(di.valor_retefuente) AS retefuente,
                SUM(di.valor_reteica)    AS reteica,
                SUM(di.valor_reteiva)    AS reteiva
            ')
            ->groupBy('t.id', 't.razon_social', 't.identificacion')
            ->orderByRaw('SUM(di.valor_retefuente + di.valor_reteica + di.valor_reteiva) DESC')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'periodo' => [
                'año'    => $anio,
                'mes'    => $mes,
                'desde'  => $desde,
                'hasta'  => $hasta,
                'nombre' => sprintf('Retenciones mensual %04d-%02d', $anio, $mes),
            ],
            'totales' => [
                'retefuente'       => $retefuente,
                'reteica'          => $reteica,
                'reteiva'          => $reteiva,
                'total_a_consignar' => round($retefuente + $reteica + $reteiva, 2),
                'base'             => round((float) ($resumen->total_base ?? 0), 2),
                'num_documentos'   => (int) ($resumen->num_documentos ?? 0),
            ],
            'detalle_proveedor' => $detalleProveedor->map(fn ($r) => [
                'tercero_id'      => $r->tercero_id,
                'proveedor'       => $r->proveedor,
                'nit'             => $r->nit,
                'num_documentos'  => (int) $r->num_documentos,
                'base'            => round((float) $r->base, 2),
                'retefuente'      => round((float) $r->retefuente, 2),
                'reteica'         => round((float) $r->reteica, 2),
                'reteiva'         => round((float) $r->reteiva, 2),
                'total_retenido'  => round((float) $r->retefuente + (float) $r->reteica + (float) $r->reteiva, 2),
            ]),
            'empresa' => [
                'nombre' => \App\Models\Tenant\Config::get('company_name', ''),
                'nit'    => \App\Models\Tenant\Config::get('company_nit', ''),
            ],
        ]);
    }

    /**
     * FEAT-B: Certificado de RETEFUENTE PRACTICADA a un proveedor en un año
     * fiscal. Lo entregamos al proveedor para que descuente el anticipo
     * en su declaración de renta (cuenta 1355xx a su favor).
     *
     * Diferencia con certificate(): allí el tercero es CLIENTE (nos retiene
     * a nosotros); aquí el tercero es PROVEEDOR (le retuvimos nosotros).
     */
    public function certificateRetefuentePracticada(Request $request, $terceroId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date',
        ]);

        $tercero = Tercero::findOrFail($terceroId);

        $resumen = DocumentoIngreso::query()
            ->where('tercero_id', $terceroId)
            ->where('estado', '!=', 'anulado')
            ->whereDate('fecha', '>=', $request->start_date)
            ->whereDate('fecha', '<=', $request->end_date)
            ->selectRaw('
                SUM(valor_retefuente) AS total_retefuente,
                SUM(valor_reteica)    AS total_reteica,
                SUM(valor_reteiva)    AS total_reteiva,
                SUM(valor_bruto)      AS total_base,
                COUNT(*)              AS num_documentos
            ')
            ->first();

        return response()->json([
            'success'    => true,
            'proveedor'  => $tercero,
            'periodo'    => [
                'desde' => $request->start_date,
                'hasta' => $request->end_date,
            ],
            'totales'    => [
                'retefuente'     => round((float) ($resumen->total_retefuente ?? 0), 2),
                'reteica'        => round((float) ($resumen->total_reteica    ?? 0), 2),
                'reteiva'        => round((float) ($resumen->total_reteiva    ?? 0), 2),
                'total_retenido' => round(
                    (float) ($resumen->total_retefuente ?? 0)
                    + (float) ($resumen->total_reteica  ?? 0)
                    + (float) ($resumen->total_reteiva  ?? 0),
                    2,
                ),
                'base'              => round((float) ($resumen->total_base ?? 0), 2),
                'num_documentos'    => (int) ($resumen->num_documentos ?? 0),
            ],
            'empresa' => [
                'nombre'    => \App\Models\Tenant\Config::get('company_name', 'Mi Empresa S.A.S'),
                'nit'       => \App\Models\Tenant\Config::get('company_nit', '900.000.000-1'),
                'direccion' => \App\Models\Tenant\Config::get('company_address', ''),
                'ciudad'    => \App\Models\Tenant\Config::get('company_city', ''),
                'telefono'  => \App\Models\Tenant\Config::get('company_phone', ''),
            ],
        ]);
    }
}
