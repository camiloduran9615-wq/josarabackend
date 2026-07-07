<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Información Exógena DIAN — medios magnéticos anuales.
 *
 * Resoluciones DIAN actuales (2024+): 000162/2023, 0011/2024.
 *
 * Formatos cubiertos por este servicio:
 *  - 1001: Pagos y retenciones practicadas a terceros.
 *  - 1003: Retenciones en la fuente que NOS practicaron (cliente retiene).
 *  - 1007: Ingresos recibidos por tercero (clientes).
 *
 * Por simplicidad de la primera iteración, los registros se devuelven
 * agregados por (tercero, concepto). El concepto se infiere de la cuenta
 * contable contra la cual se causó el pago (o por defecto se reporta como
 * "otros"). El contador hace el mapeo final a los códigos DIAN específicos
 * (5001 salarios, 5002 honorarios, 5004 servicios, 5012 compras, etc.).
 */
class InformacionExogenaService
{
    /**
     * Mapea un código de cuenta PUC a su concepto DIAN para el formato 1001.
     *
     * Catálogo simplificado (Resolución DIAN 000162/2023 — 0011/2024).
     * El contador puede ajustar el mapping final en el CSV antes de subir.
     */
    private function mapearConceptoDian(string $codigoCuenta): string
    {
        $c = (string) $codigoCuenta;

        // Salarios y conceptos laborales (5105xx + 25xx por pagar)
        if (str_starts_with($c, '5105') || str_starts_with($c, '2505') || str_starts_with($c, '2510')
            || str_starts_with($c, '2515') || str_starts_with($c, '2520') || str_starts_with($c, '2525')) {
            return '5001'; // Salarios, prestaciones y demás pagos laborales
        }
        // Honorarios (5110xx, 5205 personas naturales)
        if (str_starts_with($c, '5110') || str_starts_with($c, '511005') || str_starts_with($c, '2335')) {
            return '5002'; // Honorarios
        }
        // Comisiones
        if (str_starts_with($c, '5113') || str_starts_with($c, '5213') || str_starts_with($c, '52051')) {
            return '5003'; // Comisiones
        }
        // Arrendamientos (5120, 5220, 2335)
        if (str_starts_with($c, '5120') || str_starts_with($c, '5220') || str_starts_with($c, '233510')) {
            return '5005'; // Arrendamientos
        }
        // Publicidad / marketing (5245, 524505). Debe evaluarse antes de
        // servicios 52xx para no quedar absorbida por la categoría genérica.
        if (str_starts_with($c, '5245')) {
            return '5022'; // Publicidad y propaganda
        }
        // Servicios técnicos / mantenimiento (5145)
        if (str_starts_with($c, '5145')) {
            return '5006'; // Servicios técnicos
        }
        // Servicios generales (513x, 5235, 233515)
        if (str_starts_with($c, '5135') || str_starts_with($c, '5235') || str_starts_with($c, '233515')) {
            return '5007'; // Servicios
        }
        // Aportes parafiscales (510568-575 empleador, 510548 ARL, 237xxx por pagar)
        if (str_starts_with($c, '51056') || str_starts_with($c, '51057') || str_starts_with($c, '510548')
            || str_starts_with($c, '2370') || str_starts_with($c, '2371')) {
            return '5009'; // Aportes parafiscales y seguridad social
        }
        // Intereses y gastos financieros (53xx)
        if (str_starts_with($c, '5305') || str_starts_with($c, '530510')) {
            return '5010'; // Intereses y rendimientos financieros
        }
        // Compras de bienes (6xxx, 14xxx, 15xxx activos fijos)
        if (str_starts_with($c, '6135') || str_starts_with($c, '6205') || str_starts_with($c, '6245')
            || str_starts_with($c, '14') || str_starts_with($c, '1435') || str_starts_with($c, '1455')) {
            return '5013'; // Compras de bienes (mercancías, MP, suministros)
        }
        // Activos fijos (15xx)
        if (str_starts_with($c, '15')) {
            return '5008'; // Adquisición de activos fijos
        }
        // Seguros (5130, 1705)
        if (str_starts_with($c, '5130') || str_starts_with($c, '1705')) {
            return '5018'; // Seguros y reaseguros
        }
        // Impuestos no descontables (5115, 5215)
        if (str_starts_with($c, '5115') || str_starts_with($c, '5215')) {
            return '5044'; // Impuestos
        }
        // Default: otros gastos / pagos
        return '5055'; // Otros costos y deducciones
    }

    /**
     * Formato 1001: pagos y retenciones a terceros en el año fiscal.
     *
     * @return array<string, mixed>
     *
     * Estrategia:
     *  - JOIN con documento_ingreso_items para obtener cuenta_id por línea.
     *  - Mapea cuenta → concepto DIAN numérico (5001 salarios, 5002 honorarios, ...).
     *  - Agrupa por (tercero, concepto_dian) y suma valores proporcionales por línea.
     *  - Retenciones se distribuyen proporcionalmente al peso de la línea sobre el total bruto.
     *  - Excluye comprobantes_egreso que pagan facturas ya contabilizadas (anti-doble-conteo).
     */
    public function formato1001(int $anio): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        // ── Carga líneas con cuenta para mapear a concepto DIAN ─────────────
        // LEFT JOIN para no perder docs cuyas líneas no tienen cuenta_id
        // (en ese caso usamos código vacío → cae al default '5055').
        // `valor_linea` = total - valor_iva (porque dii.total incluye IVA).
        $lineas = DB::table('documento_ingreso_items as dii')
            ->join('documentos_ingreso as di', 'di.id', '=', 'dii.documento_ingreso_id')
            ->join('terceros as t', 't.id', '=', 'di.tercero_id')
            ->leftJoin('cuentas_contables as cc', 'cc.id', '=', 'dii.cuenta_id')
            ->whereBetween('di.fecha', [$desde, $hasta])
            ->where('di.estado', '!=', 'anulado')
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                COALESCE(cc.codigo, \'\')      AS codigo_cuenta,
                (dii.total - COALESCE(dii.valor_iva, 0)) AS valor_linea,
                COALESCE(dii.valor_iva, 0)    AS iva_linea,
                di.id                         AS doc_id,
                di.valor_bruto                AS doc_bruto,
                di.valor_retefuente           AS doc_retefuente,
                di.valor_reteica              AS doc_reteica,
                di.valor_reteiva              AS doc_reteiva,
                di.valor_total                AS doc_total
            ')
            ->get();

        // Agrupador por (tercero, concepto_dian)
        $agrupado = [];
        $docsContabilizados = []; // doc_id => true (para anti-doble-conteo con egresos)

        foreach ($lineas as $l) {
            $concepto = $this->mapearConceptoDian((string) $l->codigo_cuenta);
            $key      = $l->tercero_id . '|' . $concepto;

            // Peso de esta línea sobre el bruto del documento (para prorratear retenciones)
            $brutoDoc = (float) $l->doc_bruto > 0 ? (float) $l->doc_bruto : 1.0;
            $peso     = (float) $l->valor_linea / $brutoDoc;

            if (! isset($agrupado[$key])) {
                $agrupado[$key] = [
                    'tercero_id'     => $l->tercero_id,
                    'tipo_doc'       => $l->tipo_doc,
                    'identificacion' => $l->identificacion,
                    'dv'             => $l->dv,
                    'razon_social'   => $l->razon_social,
                    'tipo_persona'   => $l->tipo_persona,
                    'municipio'      => $l->municipio,
                    'concepto'       => $concepto,
                    'base'           => 0.0,
                    'iva'            => 0.0,
                    'retefuente'     => 0.0,
                    'reteica'        => 0.0,
                    'reteiva'        => 0.0,
                    'total_pagado'   => 0.0,
                    'num_documentos' => 0,
                    'docs_set'       => [],
                ];
            }

            $agrupado[$key]['base']         += (float) $l->valor_linea;
            $agrupado[$key]['iva']          += (float) $l->iva_linea;
            $agrupado[$key]['retefuente']   += (float) $l->doc_retefuente * $peso;
            $agrupado[$key]['reteica']      += (float) $l->doc_reteica    * $peso;
            $agrupado[$key]['reteiva']      += (float) $l->doc_reteiva    * $peso;
            $agrupado[$key]['total_pagado'] += (float) $l->doc_total      * $peso;
            $agrupado[$key]['docs_set'][$l->doc_id] = true;

            $docsContabilizados[$l->doc_id] = true;
        }

        // Documentos legacy o fixtures creados solo con encabezado no tienen
        // documento_ingreso_items. Se reportan una vez usando concepto default,
        // sin interferir con documentos que sí tienen detalle por línea.
        $documentosSinItems = DB::table('documentos_ingreso as di')
            ->join('terceros as t', 't.id', '=', 'di.tercero_id')
            ->whereBetween('di.fecha', [$desde, $hasta])
            ->where('di.estado', '!=', 'anulado')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('documento_ingreso_items as dii')
                    ->whereColumn('dii.documento_ingreso_id', 'di.id');
            })
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                di.id                         AS doc_id,
                di.valor_bruto                AS doc_bruto,
                di.valor_iva                  AS doc_iva,
                di.valor_retefuente           AS doc_retefuente,
                di.valor_reteica              AS doc_reteica,
                di.valor_reteiva              AS doc_reteiva,
                di.valor_total                AS doc_total
            ')
            ->get();

        foreach ($documentosSinItems as $d) {
            $concepto = $this->mapearConceptoDian('');
            $key      = $d->tercero_id . '|' . $concepto;

            if (! isset($agrupado[$key])) {
                $agrupado[$key] = [
                    'tercero_id'     => $d->tercero_id,
                    'tipo_doc'       => $d->tipo_doc,
                    'identificacion' => $d->identificacion,
                    'dv'             => $d->dv,
                    'razon_social'   => $d->razon_social,
                    'tipo_persona'   => $d->tipo_persona,
                    'municipio'      => $d->municipio,
                    'concepto'       => $concepto,
                    'base'           => 0.0,
                    'iva'            => 0.0,
                    'retefuente'     => 0.0,
                    'reteica'        => 0.0,
                    'reteiva'        => 0.0,
                    'total_pagado'   => 0.0,
                    'num_documentos' => 0,
                    'docs_set'       => [],
                ];
            }

            $agrupado[$key]['base']         += (float) $d->doc_bruto;
            $agrupado[$key]['iva']          += (float) $d->doc_iva;
            $agrupado[$key]['retefuente']   += (float) $d->doc_retefuente;
            $agrupado[$key]['reteica']      += (float) $d->doc_reteica;
            $agrupado[$key]['reteiva']      += (float) $d->doc_reteiva;
            $agrupado[$key]['total_pagado'] += (float) $d->doc_total;
            $agrupado[$key]['docs_set'][$d->doc_id] = true;

            $docsContabilizados[$d->doc_id] = true;
        }

        // ── Comprobantes egreso NO conciliados con factura (gasto directo) ──
        try {
            $egresosDirectos = DB::table('comprobantes_egreso as ce')
                ->join('terceros as t', 't.id', '=', 'ce.tercero_id')
                ->join('cuentas_contables as cc', 'cc.id', '=', 'ce.cuenta_debito_id')
                ->whereBetween('ce.fecha', [$desde, $hasta])
                ->where('ce.estado', '!=', 'anulado')
                // Solo egresos que NO aplicaron facturas (gasto directo, no pago de doc_ingreso)
                ->where(function ($q) {
                    $q->whereNull('ce.facturas_aplicadas')
                      ->orWhereRaw("ce.facturas_aplicadas::text IN ('[]', 'null')");
                })
                // Y cuya cuenta_debito NO sea pasivo de proveedor (clase 22/23):
                // si la cuenta_debito es 22xxx/23xxx el CE está cancelando una CxP
                // (= pago de factura ya contabilizada en doc_ingreso). Esto evita
                // doble conteo cuando facturas_aplicadas no fue diligenciado.
                ->whereRaw("LEFT(cc.codigo, 2) NOT IN ('22', '23')")
                ->selectRaw('
                    t.id                          AS tercero_id,
                    t.identificacion_documento_id AS tipo_doc,
                    t.identificacion              AS identificacion,
                    t.dv                          AS dv,
                    t.razon_social                AS razon_social,
                    COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                    COALESCE(t.municipio_id, \'\') AS municipio,
                    cc.codigo                     AS codigo_cuenta,
                    SUM(ce.valor_pagado)          AS total_pagado,
                    COUNT(*)                      AS num_egresos
                ')
                ->groupBy(
                    't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                    't.razon_social', 't.tipo_persona', 't.municipio_id', 'cc.codigo',
                )
                ->get();

            foreach ($egresosDirectos as $e) {
                $concepto = $this->mapearConceptoDian((string) $e->codigo_cuenta);
                $key      = $e->tercero_id . '|' . $concepto;

                if (! isset($agrupado[$key])) {
                    $agrupado[$key] = [
                        'tercero_id'     => $e->tercero_id,
                        'tipo_doc'       => $e->tipo_doc,
                        'identificacion' => $e->identificacion,
                        'dv'             => $e->dv,
                        'razon_social'   => $e->razon_social,
                        'tipo_persona'   => $e->tipo_persona,
                        'municipio'      => $e->municipio,
                        'concepto'       => $concepto,
                        'base'           => 0.0,
                        'iva'            => 0.0,
                        'retefuente'     => 0.0,
                        'reteica'        => 0.0,
                        'reteiva'        => 0.0,
                        'total_pagado'   => 0.0,
                        'num_documentos' => 0,
                        'docs_set'       => [],
                    ];
                }
                $agrupado[$key]['base']         += (float) $e->total_pagado;
                $agrupado[$key]['total_pagado'] += (float) $e->total_pagado;
                $agrupado[$key]['num_documentos'] += (int) $e->num_egresos;
            }
        } catch (\Throwable) {
            // Tabla no existe en este tenant — ignorar
        }

        // Materializar y ordenar
        $registros = [];
        foreach ($agrupado as $r) {
            $r['num_documentos'] = max($r['num_documentos'], count($r['docs_set']));
            unset($r['docs_set']);
            $registros[] = $r;
        }
        usort($registros, fn ($a, $b) => (float) $b['total_pagado'] <=> (float) $a['total_pagado']);

        return [
            'anio'        => $anio,
            'fecha_inicio' => $desde,
            'fecha_fin'   => $hasta,
            'registros'   => array_map(fn ($r) => [
                'tipo_documento'  => (string) ($r['tipo_doc'] ?? ''),
                'identificacion'  => (string) ($r['identificacion'] ?? ''),
                'dv'              => $r['dv'] !== null ? (string) $r['dv'] : null,
                'razon_social'    => (string) ($r['razon_social'] ?? ''),
                'tipo_persona'    => (string) ($r['tipo_persona'] ?? ''),
                'municipio'       => (string) ($r['municipio'] ?? ''),
                'concepto'        => (string) $r['concepto'],
                'base'            => round((float) $r['base'], 2),
                'iva_pagado'      => round((float) $r['iva'], 2),
                'retefuente'      => round((float) $r['retefuente'], 2),
                'reteica'         => round((float) $r['reteica'], 2),
                'reteiva'         => round((float) $r['reteiva'], 2),
                'total_pagado'    => round((float) $r['total_pagado'], 2),
                'num_documentos'  => (int) $r['num_documentos'],
            ], $registros),
            'totales' => [
                'num_terceros'      => count(array_unique(array_column($registros, 'tercero_id'))),
                'num_documentos'    => (int) array_sum(array_column($registros, 'num_documentos')),
                'base_total'        => round((float) array_sum(array_column($registros, 'base')), 2),
                'iva_total'         => round((float) array_sum(array_column($registros, 'iva')), 2),
                'retefuente_total'  => round((float) array_sum(array_column($registros, 'retefuente')), 2),
                'reteica_total'     => round((float) array_sum(array_column($registros, 'reteica')), 2),
                'reteiva_total'     => round((float) array_sum(array_column($registros, 'reteiva')), 2),
                'pagado_total'      => round((float) array_sum(array_column($registros, 'total_pagado')), 2),
            ],
        ];
    }

    /**
     * Formato 1003: retenciones en la fuente que NOS practicaron en el año.
     *
     * Fuente: factura_retenciones (registradas por nosotros al emitir la factura).
     */
    /**
     * @return array<string, mixed>
     */
    public function formato1003(int $anio): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $registros = DB::table('factura_retenciones as fr')
            ->join('facturas as f', 'f.id', '=', 'fr.factura_id')
            ->join('terceros as t', 't.id', '=', 'f.tercero_id')
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->where('f.estado', '!=', 'anulado')
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                fr.codigo                     AS codigo_retencion,
                fr.nombre                     AS nombre_retencion,
                SUM(fr.base)                  AS base,
                SUM(fr.valor)                 AS valor_retenido,
                COUNT(*)                      AS num_facturas
            ')
            ->groupBy(
                't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                't.razon_social', 't.tipo_persona', 't.municipio_id',
                'fr.codigo', 'fr.nombre',
            )
            ->orderByDesc(DB::raw('SUM(fr.valor)'))
            ->get();

        return [
            'anio'         => $anio,
            'fecha_inicio' => $desde,
            'fecha_fin'    => $hasta,
            'registros'    => $registros->map(fn ($r) => [
                'tipo_documento'    => (string) ($r->tipo_doc ?? ''),
                'identificacion'    => (string) ($r->identificacion ?? ''),
                'dv'                => $r->dv !== null ? (string) $r->dv : null,
                'razon_social'      => (string) ($r->razon_social ?? ''),
                'tipo_persona'      => (string) ($r->tipo_persona ?? ''),
                'municipio'         => (string) ($r->municipio ?? ''),
                'codigo_retencion'  => (string) ($r->codigo_retencion ?? ''),
                'nombre_retencion'  => (string) ($r->nombre_retencion ?? ''),
                'base'              => round((float) $r->base, 2),
                'valor_retenido'    => round((float) $r->valor_retenido, 2),
                'num_facturas'      => (int) $r->num_facturas,
            ])->values()->all(),
            'totales' => [
                'num_terceros'   => $registros->unique('tercero_id')->count(),
                'num_facturas'   => (int) $registros->sum('num_facturas'),
                'base_total'     => round((float) $registros->sum('base'), 2),
                'retenido_total' => round((float) $registros->sum('valor_retenido'), 2),
            ],
        ];
    }

    /**
     * Formato 1005: IVA DESCONTABLE por tercero en el año.
     *
     * Reporta cuánto IVA pagamos a cada proveedor en compras durante el año.
     * Sirve para conciliar con las declaraciones bimestrales (Formulario 300).
     *
     * Fuente: documentos_ingreso.valor_iva agrupado por proveedor.
     */
    /**
     * @return array<string, mixed>
     */
    public function formato1005(int $anio): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $registros = DB::table('documentos_ingreso as di')
            ->join('terceros as t', 't.id', '=', 'di.tercero_id')
            ->whereBetween('di.fecha', [$desde, $hasta])
            ->where('di.estado', '!=', 'anulado')
            ->where('di.valor_iva', '>', 0)
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                SUM(di.valor_bruto)           AS base_gravada,
                SUM(di.valor_iva)             AS iva_descontable,
                COUNT(*)                      AS num_documentos
            ')
            ->groupBy(
                't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                't.razon_social', 't.tipo_persona', 't.municipio_id',
            )
            ->orderByDesc(DB::raw('SUM(di.valor_iva)'))
            ->get();

        return [
            'anio'         => $anio,
            'fecha_inicio' => $desde,
            'fecha_fin'    => $hasta,
            'registros'    => $registros->map(fn ($r) => [
                'tipo_documento'   => (string) ($r->tipo_doc ?? ''),
                'identificacion'   => (string) ($r->identificacion ?? ''),
                'dv'               => $r->dv !== null ? (string) $r->dv : null,
                'razon_social'     => (string) ($r->razon_social ?? ''),
                'tipo_persona'     => (string) ($r->tipo_persona ?? ''),
                'municipio'        => (string) ($r->municipio ?? ''),
                'base_gravada'     => round((float) $r->base_gravada, 2),
                'iva_descontable'  => round((float) $r->iva_descontable, 2),
                'num_documentos'   => (int) $r->num_documentos,
            ])->values()->all(),
            'totales' => [
                'num_terceros'          => $registros->count(),
                'num_documentos'        => (int) $registros->sum('num_documentos'),
                'base_total'            => round((float) $registros->sum('base_gravada'), 2),
                'iva_descontable_total' => round((float) $registros->sum('iva_descontable'), 2),
            ],
        ];
    }

    /**
     * Formato 1006: IVA GENERADO por tercero en el año.
     *
     * Reporta cuánto IVA cobramos a cada cliente. Reconcilia con Formularios 300.
     */
    /**
     * @return array<string, mixed>
     */
    public function formato1006(int $anio): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $registros = DB::table('facturas as f')
            ->join('terceros as t', 't.id', '=', 'f.tercero_id')
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->where('f.estado', '!=', 'anulado')
            ->where('f.valor_impuestos', '>', 0)
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                SUM(f.valor_bruto - COALESCE(f.valor_descuentos, 0)) AS base_gravada,
                SUM(f.valor_impuestos)        AS iva_generado,
                COUNT(*)                      AS num_facturas
            ')
            ->groupBy(
                't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                't.razon_social', 't.tipo_persona', 't.municipio_id',
            )
            ->orderByDesc(DB::raw('SUM(f.valor_impuestos)'))
            ->get();

        return [
            'anio'         => $anio,
            'fecha_inicio' => $desde,
            'fecha_fin'    => $hasta,
            'registros'    => $registros->map(fn ($r) => [
                'tipo_documento'  => (string) ($r->tipo_doc ?? ''),
                'identificacion'  => (string) ($r->identificacion ?? ''),
                'dv'              => $r->dv !== null ? (string) $r->dv : null,
                'razon_social'    => (string) ($r->razon_social ?? ''),
                'tipo_persona'    => (string) ($r->tipo_persona ?? ''),
                'municipio'       => (string) ($r->municipio ?? ''),
                'base_gravada'    => round((float) $r->base_gravada, 2),
                'iva_generado'    => round((float) $r->iva_generado, 2),
                'num_facturas'    => (int) $r->num_facturas,
            ])->values()->all(),
            'totales' => [
                'num_terceros'       => $registros->count(),
                'num_facturas'       => (int) $registros->sum('num_facturas'),
                'base_total'         => round((float) $registros->sum('base_gravada'), 2),
                'iva_generado_total' => round((float) $registros->sum('iva_generado'), 2),
            ],
        ];
    }

    /**
     * Formato 1008: SALDOS DE CXC comerciales al cierre del año.
     *
     * Por cada tercero con saldo en grupo 13 (deudores) reporta el saldo
     * pendiente al 31-dic. Solo incluye saldos > 0.
     *
     * EXCLUYE:
     *   1355xx Anticipos de impuestos (retefuente, renta, ICA) → derechos con DIAN
     *   1380xx Deudas a empleados/socios → relaciones internas no comerciales (informe separado)
     */
    /**
     * @return array<string, mixed>
     */
    public function formato1008(int $anio): array
    {
        $hasta = sprintf('%04d-12-31', $anio);

        $registros = DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->join('terceros as t', 't.id', '=', 'al.tercero_id')
            ->where('a.estado', 'aprobado')
            ->where('a.fecha', '<=', $hasta)
            ->whereRaw("LEFT(cc.codigo, 2) = '13'")
            ->whereRaw("LEFT(cc.codigo, 4) NOT IN ('1355', '1380')")
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                SUM(al.debito - al.credito)   AS saldo
            ')
            ->groupBy(
                't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                't.razon_social', 't.tipo_persona', 't.municipio_id',
            )
            ->havingRaw('SUM(al.debito - al.credito) > 0')
            ->orderByDesc(DB::raw('SUM(al.debito - al.credito)'))
            ->get();

        return [
            'anio'         => $anio,
            'fecha_corte'  => $hasta,
            'registros'    => $registros->map(fn ($r) => [
                'tipo_documento' => (string) ($r->tipo_doc ?? ''),
                'identificacion' => (string) ($r->identificacion ?? ''),
                'dv'             => $r->dv !== null ? (string) $r->dv : null,
                'razon_social'   => (string) ($r->razon_social ?? ''),
                'tipo_persona'   => (string) ($r->tipo_persona ?? ''),
                'municipio'      => (string) ($r->municipio ?? ''),
                'saldo_cxc'      => round((float) $r->saldo, 2),
            ])->values()->all(),
            'totales' => [
                'num_terceros' => $registros->count(),
                'saldo_total'  => round((float) $registros->sum('saldo'), 2),
            ],
        ];
    }

    /**
     * Formato 1009: SALDOS DE CXP a PROVEEDORES al cierre del año.
     *
     * Por cada tercero con saldo en grupo 22 (Proveedores) y subgrupos de 23
     * (CxP a acreedores varios), reporta el saldo pendiente al 31-dic.
     *
     * EXCLUYE explícitamente:
     *   2365-2368: Retención en la Fuente / IVA / ICA practicada → se debe a DIAN/municipio
     *   2370-2375: Aportes a la seguridad social → se debe a EPS/AFP/ARL
     *   2380-2390: Acreedores oficiales / cuotas / depósitos → fiscales especiales
     *
     * Solo incluye CxP a proveedores y acreedores comerciales reales. Solo saldos > 0.
     */
    /**
     * @return array<string, mixed>
     */
    public function formato1009(int $anio): array
    {
        $hasta = sprintf('%04d-12-31', $anio);

        $registros = DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->join('cuentas_contables as cc', 'cc.id', '=', 'al.cuenta_id')
            ->join('terceros as t', 't.id', '=', 'al.tercero_id')
            ->where('a.estado', 'aprobado')
            ->where('a.fecha', '<=', $hasta)
            // Incluir grupo 22 (proveedores) completo + sub-grupos de 23 que son comerciales,
            // excluyendo explícitamente retenciones (236x), aportes SS (237x) y oficiales (238x-239x).
            ->where(function ($q) {
                $q->whereRaw("LEFT(cc.codigo, 2) = '22'")
                  ->orWhere(function ($q2) {
                      $q2->whereRaw("LEFT(cc.codigo, 2) = '23'")
                         ->whereRaw("LEFT(cc.codigo, 3) NOT IN ('236', '237', '238', '239')");
                  });
            })
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                SUM(al.credito - al.debito)   AS saldo
            ')
            ->groupBy(
                't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                't.razon_social', 't.tipo_persona', 't.municipio_id',
            )
            ->havingRaw('SUM(al.credito - al.debito) > 0')
            ->orderByDesc(DB::raw('SUM(al.credito - al.debito)'))
            ->get();

        return [
            'anio'         => $anio,
            'fecha_corte'  => $hasta,
            'registros'    => $registros->map(fn ($r) => [
                'tipo_documento' => (string) ($r->tipo_doc ?? ''),
                'identificacion' => (string) ($r->identificacion ?? ''),
                'dv'             => $r->dv !== null ? (string) $r->dv : null,
                'razon_social'   => (string) ($r->razon_social ?? ''),
                'tipo_persona'   => (string) ($r->tipo_persona ?? ''),
                'municipio'      => (string) ($r->municipio ?? ''),
                'saldo_cxp'      => round((float) $r->saldo, 2),
            ])->values()->all(),
            'totales' => [
                'num_terceros' => $registros->count(),
                'saldo_total'  => round((float) $registros->sum('saldo'), 2),
            ],
        ];
    }

    /**
     * Formato 1007: ingresos recibidos por tercero (cliente) en el año fiscal.
     *
     * Fuente: facturas no anuladas, agrupadas por cliente.
     */
    /**
     * @return array<string, mixed>
     */
    public function formato1007(int $anio): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $registros = DB::table('facturas as f')
            ->join('terceros as t', 't.id', '=', 'f.tercero_id')
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->where('f.estado', '!=', 'anulado')
            ->selectRaw('
                t.id                          AS tercero_id,
                t.identificacion_documento_id AS tipo_doc,
                t.identificacion              AS identificacion,
                t.dv                          AS dv,
                t.razon_social                AS razon_social,
                COALESCE(t.tipo_persona, \'Persona Juridica\') AS tipo_persona,
                COALESCE(t.municipio_id, \'\') AS municipio,
                SUM(f.valor_bruto - COALESCE(f.valor_descuentos, 0)) AS base,
                SUM(f.valor_impuestos)         AS iva,
                SUM(f.valor_descuentos)        AS descuentos,
                SUM(f.valor_total)             AS total_facturado,
                COUNT(*)                      AS num_facturas
            ')
            ->groupBy(
                't.id', 't.identificacion_documento_id', 't.identificacion', 't.dv',
                't.razon_social', 't.tipo_persona', 't.municipio_id',
            )
            ->orderByDesc(DB::raw('SUM(f.valor_total)'))
            ->get();

        return [
            'anio'         => $anio,
            'fecha_inicio' => $desde,
            'fecha_fin'    => $hasta,
            'registros'    => $registros->map(fn ($r) => [
                'tipo_documento'  => (string) ($r->tipo_doc ?? ''),
                'identificacion'  => (string) ($r->identificacion ?? ''),
                'dv'              => $r->dv !== null ? (string) $r->dv : null,
                'razon_social'    => (string) ($r->razon_social ?? ''),
                'tipo_persona'    => (string) ($r->tipo_persona ?? ''),
                'municipio'       => (string) ($r->municipio ?? ''),
                'base'            => round((float) $r->base, 2),
                'iva'             => round((float) $r->iva, 2),
                'descuentos'      => round((float) $r->descuentos, 2),
                'total_facturado' => round((float) $r->total_facturado, 2),
                'num_facturas'    => (int) $r->num_facturas,
            ])->values()->all(),
            'totales' => [
                'num_terceros'        => $registros->count(),
                'num_facturas'        => (int) $registros->sum('num_facturas'),
                'base_total'          => round((float) $registros->sum('base'), 2),
                'iva_total'           => round((float) $registros->sum('iva'), 2),
                'descuentos_total'    => round((float) $registros->sum('descuentos'), 2),
                'facturado_total'     => round((float) $registros->sum('total_facturado'), 2),
            ],
        ];
    }
}
