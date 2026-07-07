<?php

declare(strict_types=1);

namespace App\Services\Reportes;

/**
 * Exporta Estados Financieros NIIF a CSV pipe-delimited.
 *
 * Útil para:
 *  - Asamblea ordinaria de accionistas (sociedades comerciales)
 *  - Reporte anual a Supersociedades (Decreto 2420/2015)
 *  - Plantillas Excel del contador
 *
 * Cubre los 5 estados financieros disponibles:
 *  - Balance General (NIC 1)
 *  - Estado de Resultados (NIC 1)
 *  - Estado de Cambios en el Patrimonio (NIC 1)
 *  - Estado de Flujo de Efectivo (NIC 7)
 *  - Balance de Comprobación (12 columnas)
 *
 * Formato: pipe-delimited UTF-8 BOM.
 */
class EstadosFinancierosCsvExporter
{
    private const DELIM = '|';

    public function balanceGeneral(array $data): string
    {
        $headers = ['seccion', 'grupo_codigo', 'grupo_nombre', 'codigo', 'nombre', 'saldo'];
        $rows = [];

        // Activos
        foreach (($data['activo']['subsecciones'] ?? []) as $sub) {
            foreach (($sub['grupos'] ?? []) as $g) {
                foreach (($g['cuentas'] ?? []) as $c) {
                    $rows[] = [
                        'ACTIVO',
                        $g['codigo'] ?? '',
                        $g['nombre'] ?? '',
                        $c['codigo'] ?? '',
                        $c['nombre'] ?? '',
                        $this->montoEntero($c['saldo'] ?? 0),
                    ];
                }
            }
        }
        // Pasivos
        foreach (($data['pasivo']['subsecciones'] ?? []) as $sub) {
            foreach (($sub['grupos'] ?? []) as $g) {
                foreach (($g['cuentas'] ?? []) as $c) {
                    $rows[] = [
                        'PASIVO',
                        $g['codigo'] ?? '',
                        $g['nombre'] ?? '',
                        $c['codigo'] ?? '',
                        $c['nombre'] ?? '',
                        $this->montoEntero($c['saldo'] ?? 0),
                    ];
                }
            }
        }
        // Patrimonio
        foreach (($data['patrimonio']['subsecciones'] ?? []) as $sub) {
            foreach (($sub['grupos'] ?? []) as $g) {
                foreach (($g['cuentas'] ?? []) as $c) {
                    $rows[] = [
                        'PATRIMONIO',
                        $g['codigo'] ?? '',
                        $g['nombre'] ?? '',
                        $c['codigo'] ?? '',
                        $c['nombre'] ?? '',
                        $this->montoEntero($c['saldo'] ?? 0),
                    ];
                }
            }
        }

        // Línea de totales al final
        $rows[] = ['TOTAL', '', 'Total Activo',     '', '', $this->montoEntero($data['activo']['total']     ?? 0)];
        $rows[] = ['TOTAL', '', 'Total Pasivo',     '', '', $this->montoEntero($data['pasivo']['total']     ?? 0)];
        $rows[] = ['TOTAL', '', 'Total Patrimonio', '', '', $this->montoEntero($data['patrimonio']['total'] ?? 0)];

        return $this->armarCsv($headers, $rows);
    }

    public function estadoResultados(array $data): string
    {
        $headers = ['seccion', 'codigo', 'nombre', 'valor'];
        $rows = [];

        // Ingresos
        foreach (($data['ingresos']['lineas'] ?? []) as $l) {
            $rows[] = ['INGRESO', $l['codigo'] ?? '', $l['nombre'] ?? '', $this->montoEntero($l['saldo'] ?? 0)];
        }
        $rows[] = ['SUBTOTAL', '4', 'Total Ingresos', $this->montoEntero($data['ingresos']['total'] ?? 0)];

        // Costos
        foreach (($data['costo_ventas']['lineas'] ?? []) as $l) {
            $rows[] = ['COSTO', $l['codigo'] ?? '', $l['nombre'] ?? '', $this->montoEntero($l['saldo'] ?? 0)];
        }
        $rows[] = ['SUBTOTAL', '6', 'Total Costo de Ventas', $this->montoEntero($data['costo_ventas']['total'] ?? 0)];
        $rows[] = ['UTILIDAD', '', 'Utilidad Bruta', $this->montoEntero($data['utilidad_bruta'] ?? 0)];

        // Gastos
        foreach (($data['gastos_operacionales']['lineas'] ?? []) as $l) {
            $rows[] = ['GASTO', $l['codigo'] ?? '', $l['nombre'] ?? '', $this->montoEntero($l['saldo'] ?? 0)];
        }
        $rows[] = ['SUBTOTAL', '5', 'Total Gastos Operacionales', $this->montoEntero($data['gastos_operacionales']['total'] ?? 0)];
        $rows[] = ['UTILIDAD', '', 'Utilidad Operacional', $this->montoEntero($data['utilidad_operacional'] ?? 0)];

        // Otros
        foreach (($data['otros_ingresos_egresos']['lineas'] ?? []) as $l) {
            $rows[] = ['OTRO', $l['codigo'] ?? '', $l['nombre'] ?? '', $this->montoEntero($l['saldo'] ?? 0)];
        }
        $rows[] = ['UTILIDAD', '', 'Utilidad antes de Impuesto', $this->montoEntero($data['utilidad_antes_impuesto'] ?? 0)];

        // Impuesto + neta
        foreach (($data['impuesto_renta']['lineas'] ?? []) as $l) {
            $rows[] = ['IMPUESTO', $l['codigo'] ?? '', $l['nombre'] ?? '', $this->montoEntero($l['saldo'] ?? 0)];
        }
        $rows[] = ['TOTAL', '', 'Utilidad Neta del Ejercicio', $this->montoEntero($data['utilidad_neta'] ?? 0)];

        return $this->armarCsv($headers, $rows);
    }

    public function estadoCambiosPatrimonio(array $data): string
    {
        $headers = ['categoria_codigo', 'categoria', 'codigo_cuenta', 'nombre_cuenta',
            'saldo_inicial', 'aumentos', 'disminuciones', 'saldo_final'];
        $rows = [];
        foreach (($data['categorias'] ?? []) as $cat) {
            foreach (($cat['cuentas'] ?? []) as $c) {
                $rows[] = [
                    $cat['codigo'] ?? '',
                    $cat['nombre'] ?? '',
                    $c['codigo'] ?? '',
                    $c['nombre'] ?? '',
                    $this->montoEntero($c['saldo_inicial'] ?? 0),
                    $this->montoEntero($c['aumentos'] ?? 0),
                    $this->montoEntero($c['disminuciones'] ?? 0),
                    $this->montoEntero($c['saldo_final'] ?? 0),
                ];
            }
            // Subtotal por categoría
            $rows[] = [
                $cat['codigo'] ?? '',
                'TOTAL ' . ($cat['nombre'] ?? ''),
                '', '',
                $this->montoEntero($cat['saldo_inicial'] ?? 0),
                $this->montoEntero($cat['aumentos'] ?? 0),
                $this->montoEntero($cat['disminuciones'] ?? 0),
                $this->montoEntero($cat['saldo_final'] ?? 0),
            ];
        }
        // Totales generales
        $rows[] = [
            'TOTAL', 'PATRIMONIO TOTAL', '', '',
            $this->montoEntero($data['totales']['saldo_inicial'] ?? 0),
            $this->montoEntero($data['totales']['aumentos'] ?? 0),
            $this->montoEntero($data['totales']['disminuciones'] ?? 0),
            $this->montoEntero($data['totales']['saldo_final'] ?? 0),
        ];

        return $this->armarCsv($headers, $rows);
    }

    public function flujoEfectivo(array $data): string
    {
        $headers = ['actividad', 'codigo_grupo', 'rubro', 'flujo_caja'];
        $rows = [];

        // OPERACIÓN
        $rows[] = ['OPERACION', '',  'Utilidad neta del periodo', $this->montoEntero($data['operacion']['utilidad_neta'] ?? 0)];
        $rows[] = ['OPERACION', '',  'Depreciación', $this->montoEntero($data['operacion']['depreciacion'] ?? 0)];
        foreach (($data['operacion']['cambios_capital_trabajo'] ?? []) as $c) {
            $rows[] = ['OPERACION', $c['grupo'] ?? '', $c['rubro'] ?? '', $this->montoEntero($c['flujo_caja'] ?? 0)];
        }
        $rows[] = ['SUBTOTAL', '', 'Total Operación', $this->montoEntero($data['operacion']['total'] ?? 0)];

        // INVERSIÓN
        foreach (($data['inversion']['movimientos'] ?? []) as $m) {
            $rows[] = ['INVERSION', $m['grupo'] ?? '', $m['rubro'] ?? '', $this->montoEntero($m['flujo_caja'] ?? 0)];
        }
        $rows[] = ['SUBTOTAL', '', 'Total Inversión', $this->montoEntero($data['inversion']['total'] ?? 0)];

        // FINANCIACIÓN
        foreach (($data['financiacion']['movimientos'] ?? []) as $m) {
            $rows[] = ['FINANCIACION', $m['grupo'] ?? '', $m['rubro'] ?? '', $this->montoEntero($m['flujo_caja'] ?? 0)];
        }
        $rows[] = ['SUBTOTAL', '', 'Total Financiación', $this->montoEntero($data['financiacion']['total'] ?? 0)];

        // Efectivo
        $rows[] = ['EFECTIVO', '', 'Aumento/(Disminución) en efectivo', $this->montoEntero($data['aumento_efectivo'] ?? 0)];
        $rows[] = ['EFECTIVO', '', 'Efectivo inicial', $this->montoEntero($data['efectivo_inicial'] ?? 0)];
        $rows[] = ['EFECTIVO', '', 'Efectivo final calculado', $this->montoEntero($data['efectivo_final_calculado'] ?? 0)];
        $rows[] = ['EFECTIVO', '', 'Efectivo final real (libros)', $this->montoEntero($data['efectivo_final_real'] ?? 0)];

        return $this->armarCsv($headers, $rows);
    }

    public function balanceComprobacion(array $data): string
    {
        $headers = ['codigo', 'nombre', 'clase', 'naturaleza',
            'si_debito', 'si_credito', 'mov_debito', 'mov_credito',
            'sf_debito', 'sf_credito', 'aj_debito', 'aj_credito',
            'sa_debito', 'sa_credito'];
        $rows = [];
        foreach (($data['filas'] ?? []) as $f) {
            $rows[] = [
                $f['codigo'] ?? '',
                $f['nombre'] ?? '',
                $f['clase'] ?? '',
                $f['naturaleza'] ?? '',
                $this->montoEntero($f['saldoInicialDebito'] ?? 0),
                $this->montoEntero($f['saldoInicialCredito'] ?? 0),
                $this->montoEntero($f['movimientoDebito'] ?? 0),
                $this->montoEntero($f['movimientoCredito'] ?? 0),
                $this->montoEntero($f['saldoFinalDebito'] ?? 0),
                $this->montoEntero($f['saldoFinalCredito'] ?? 0),
                $this->montoEntero($f['ajusteDebito'] ?? 0),
                $this->montoEntero($f['ajusteCredito'] ?? 0),
                $this->montoEntero($f['saldoAjustadoDebito'] ?? 0),
                $this->montoEntero($f['saldoAjustadoCredito'] ?? 0),
            ];
        }
        return $this->armarCsv($headers, $rows);
    }

    private function montoEntero(mixed $valor): string
    {
        return (string) (int) round((float) $valor);
    }

    /**
     * @param list<string> $headers
     * @param list<array<int|string, mixed>> $rows
     */
    private function armarCsv(array $headers, array $rows): string
    {
        $bom = "\xEF\xBB\xBF";
        $lineas = [];
        $lineas[] = $this->fila($headers);
        foreach ($rows as $row) {
            $lineas[] = $this->fila($row);
        }
        return $bom . implode("\n", $lineas) . "\n";
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function fila(array $row): string
    {
        $cells = array_map(function ($cell): string {
            return str_replace([self::DELIM, "\n", "\r"], ' ', (string) $cell);
        }, $row);
        return implode(self::DELIM, $cells);
    }
}
