<?php

declare(strict_types=1);

namespace App\Services\Conciliacion;

use Illuminate\Support\Facades\DB;

/**
 * Genera el "papel de trabajo" de conciliación bancaria:
 *   - Saldo del extracto (banco)
 *   - Saldo del libro contable (cuenta del banco, ej. 111005)
 *   - Partidas conciliatorias (lo que falta a uno y al otro)
 *   - Diferencia (debería ser 0 si la conciliación es perfecta)
 *
 * Partidas conciliatorias estándar (Audit Standard 4):
 *
 *   1. Consignaciones no registradas en libros
 *      Líneas del extracto con crédito > 0 sin conciliar.
 *      Banco las tiene; libro aún no. Se suma al libro para igualar.
 *
 *   2. Cheques girados no cobrados
 *      Comprobantes de egreso del periodo sin línea de extracto.
 *      Libro los registró; banco aún no. Se resta del banco para igualar.
 *
 *   3. Notas débito del banco no registradas
 *      Líneas del extracto con débito > 0 sin conciliar.
 *      Ej: comisiones bancarias, GMF 4×1000. Se resta del libro.
 *
 *   4. Notas crédito del banco no registradas
 *      Líneas del extracto con crédito > 0 sin conciliar que no son consignaciones.
 *      Ej: intereses ganados. (Implementación práctica: se trata igual que #1).
 */
class ReporteConciliacionService
{
    public function generar(string $extractoId, ?string $cuentaContableId = null): array
    {
        $extracto = DB::table('extractos_bancarios')->where('id', $extractoId)->first();
        if ($extracto === null) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Extracto bancario {$extractoId} no encontrado.",
            );
        }

        // ── 1. Líneas del extracto pendientes (no conciliadas) ─────────────
        $pendientes = DB::table('lineas_extracto')
            ->where('extracto_id', $extractoId)
            ->where('estado_conciliacion', 'pendiente')
            ->orderBy('fecha')
            ->get();

        $consignacionesNoRegistradas = [];
        $notasDebitoBancoNoRegistradas = [];

        foreach ($pendientes as $linea) {
            $entrada = [
                'linea_id'    => $linea->id,
                'fecha'       => (string) $linea->fecha,
                'descripcion' => (string) ($linea->descripcion ?? ''),
                'referencia'  => (string) ($linea->referencia ?? ''),
                'debito'      => round((float) $linea->debito,  2),
                'credito'     => round((float) $linea->credito, 2),
            ];
            if ((float) $linea->credito > 0) {
                $consignacionesNoRegistradas[] = $entrada;
            } elseif ((float) $linea->debito > 0) {
                $notasDebitoBancoNoRegistradas[] = $entrada;
            }
        }

        // ── 2. Cheques girados no cobrados (egresos sin línea extracto) ─────
        // Buscamos comprobantes_egreso en el periodo del extracto que NO
        // tengan conciliación. El beneficiario se obtiene de la relación con terceros.
        $chequesNoCobrados = DB::table('comprobantes_egreso as ce')
            ->leftJoin('terceros as t', 't.id', '=', 'ce.tercero_id')
            ->whereBetween('ce.fecha', [
                (string) $extracto->periodo_inicio,
                (string) $extracto->periodo_fin,
            ])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('conciliaciones')
                  ->where('origen_type', 'ComprobanteEgreso')
                  ->whereColumn('origen_id', 'ce.id');
            })
            ->select(
                'ce.id', 'ce.numero', 'ce.fecha', 'ce.concepto', 'ce.valor_pagado',
                't.razon_social AS beneficiario',
            )
            ->orderBy('ce.fecha')
            ->get()
            ->map(fn ($r) => [
                'comprobante_id' => $r->id,
                'numero'         => (string) ($r->numero ?? ''),
                'fecha'          => (string) ($r->fecha ?? ''),
                'beneficiario'   => (string) ($r->beneficiario ?? ''),
                'concepto'       => (string) ($r->concepto ?? ''),
                'valor'          => round((float) $r->valor_pagado, 2),
            ])
            ->all();

        // ── 3. Saldo libro (si se proporciona cuenta_contable_id) ───────────
        $saldoLibro = null;
        if ($cuentaContableId !== null) {
            $saldoLibro = $this->calcularSaldoLibro($cuentaContableId, (string) $extracto->periodo_fin);
        }

        // ── 4. Conciliación matemática ──────────────────────────────────────
        $saldoBanco = round((float) $extracto->saldo_final, 2);

        $totalConsignacionesPendientes = (float) collect($consignacionesNoRegistradas)->sum('credito');
        $totalNotasDebitoPendientes    = (float) collect($notasDebitoBancoNoRegistradas)->sum('debito');
        $totalChequesNoCobrados        = (float) collect($chequesNoCobrados)->sum('valor');

        // Si conocemos el saldo libro, calculamos la diferencia
        // Saldo banco ajustado = saldo banco - cheques no cobrados (porque el banco aún no los descontó)
        //                                    + (no aplica: las notas débito banco YA están en saldo banco)
        // Saldo libro ajustado = saldo libro + consignaciones pendientes (porque el libro aún no las tiene)
        //                                    - notas débito banco (comisiones aún no registradas)
        $saldoBancoAjustado = round($saldoBanco - $totalChequesNoCobrados, 2);
        $saldoLibroAjustado = $saldoLibro !== null
            ? round($saldoLibro + $totalConsignacionesPendientes - $totalNotasDebitoPendientes, 2)
            : null;

        $diferencia = $saldoLibroAjustado !== null
            ? round($saldoBancoAjustado - $saldoLibroAjustado, 2)
            : null;

        return [
            'extracto' => [
                'id'             => (string) $extracto->id,
                'banco'          => (string) $extracto->banco,
                'numero_cuenta'  => (string) $extracto->numero_cuenta,
                'periodo_inicio' => (string) $extracto->periodo_inicio,
                'periodo_fin'    => (string) $extracto->periodo_fin,
                'saldo_inicial'  => round((float) $extracto->saldo_inicial, 2),
                'saldo_final'    => $saldoBanco,
            ],
            'saldo_libro'        => $saldoLibro,
            'cuenta_contable_id' => $cuentaContableId,

            'partidas_conciliatorias' => [
                'consignaciones_no_registradas' => [
                    'detalle' => $consignacionesNoRegistradas,
                    'total'   => round($totalConsignacionesPendientes, 2),
                ],
                'cheques_no_cobrados' => [
                    'detalle' => $chequesNoCobrados,
                    'total'   => round($totalChequesNoCobrados, 2),
                ],
                'notas_debito_banco_no_registradas' => [
                    'detalle' => $notasDebitoBancoNoRegistradas,
                    'total'   => round($totalNotasDebitoPendientes, 2),
                ],
            ],

            'conciliacion_matematica' => [
                'saldo_banco_segun_extracto'   => $saldoBanco,
                'menos_cheques_no_cobrados'    => round($totalChequesNoCobrados, 2),
                'saldo_banco_ajustado'         => $saldoBancoAjustado,

                'saldo_libro_segun_contabilidad' => $saldoLibro,
                'mas_consignaciones_pendientes'  => round($totalConsignacionesPendientes, 2),
                'menos_notas_debito_banco'       => round($totalNotasDebitoPendientes, 2),
                'saldo_libro_ajustado'           => $saldoLibroAjustado,

                'diferencia'                   => $diferencia,
                'conciliado'                   => $diferencia !== null && abs($diferencia) < 0.01,
            ],
        ];
    }

    /**
     * Saldo de la cuenta contable en libros al cierre del periodo.
     * Suma de débitos menos créditos de TODOS los asientos aprobados
     * con fecha <= $hasta sobre la cuenta dada.
     *
     * Nota: esta es la fórmula directa sobre asiento_items. Asume cuenta
     * de naturaleza débito (caso típico de 11xxxx Bancos). Para otras
     * naturalezas el llamador interpretará el signo.
     */
    private function calcularSaldoLibro(string $cuentaId, string $hasta): float
    {
        $row = DB::table('asiento_items as al')
            ->join('asientos as a', 'a.id', '=', 'al.asiento_id')
            ->where('al.cuenta_id', $cuentaId)
            ->where('a.estado', 'aprobado')
            ->where('a.fecha', '<=', $hasta)
            ->selectRaw('SUM(al.debito - al.credito) AS saldo')
            ->first();

        return round((float) ($row->saldo ?? 0), 2);
    }
}
