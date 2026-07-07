<?php

declare(strict_types=1);

namespace App\Services\Reportes;

/**
 * Exporta los reportes de Información Exógena DIAN a CSV pipe-delimited.
 *
 * Formato:
 *   - Delimitador: `|` (estándar DIAN MUISCA)
 *   - Encoding: UTF-8 con BOM (para que Excel lo abra correctamente)
 *   - Headers: nombres en español sin acentos (compatible Excel y MUISCA)
 *   - Numéricos: enteros sin decimales (centavos redondeados, formato DIAN)
 *
 * Cada formato tiene su propio set de columnas según la Resolución DIAN
 * vigente. Las columnas devueltas son las MÁS USADAS — el contador puede
 * mapear contra la plantilla oficial del año (las posiciones varían).
 */
class ExogenaCsvExporter
{
    private const DELIM = '|';

    public function exportar(int $formato, array $data): string
    {
        return match ($formato) {
            1001 => $this->csv1001($data),
            1003 => $this->csv1003($data),
            1005 => $this->csv1005($data),
            1006 => $this->csv1006($data),
            1007 => $this->csv1007($data),
            1008 => $this->csv1008($data),
            1009 => $this->csv1009($data),
            default => throw new \InvalidArgumentException("Formato {$formato} no soportado."),
        };
    }

    private function csv1001(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'concepto', 'base', 'iva_pagado',
            'retefuente', 'reteica', 'reteiva', 'total_pagado',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $r['concepto'] ?? '',
            $this->montoEntero($r['base'] ?? 0),
            $this->montoEntero($r['iva_pagado'] ?? 0),
            $this->montoEntero($r['retefuente'] ?? 0),
            $this->montoEntero($r['reteica'] ?? 0),
            $this->montoEntero($r['reteiva'] ?? 0),
            $this->montoEntero($r['total_pagado'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    private function csv1003(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'codigo_retencion', 'nombre_retencion',
            'base', 'valor_retenido',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $r['codigo_retencion'] ?? '',
            $r['nombre_retencion'] ?? '',
            $this->montoEntero($r['base'] ?? 0),
            $this->montoEntero($r['valor_retenido'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    private function csv1005(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'base_gravada', 'iva_descontable',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $this->montoEntero($r['base_gravada'] ?? 0),
            $this->montoEntero($r['iva_descontable'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    private function csv1006(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'base_gravada', 'iva_generado',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $this->montoEntero($r['base_gravada'] ?? 0),
            $this->montoEntero($r['iva_generado'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    private function csv1007(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'base', 'iva', 'descuentos', 'total_facturado',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $this->montoEntero($r['base'] ?? 0),
            $this->montoEntero($r['iva'] ?? 0),
            $this->montoEntero($r['descuentos'] ?? 0),
            $this->montoEntero($r['total_facturado'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    private function csv1008(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'saldo_cxc',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $this->montoEntero($r['saldo_cxc'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    private function csv1009(array $data): string
    {
        $headers = [
            'tipo_documento', 'identificacion', 'dv', 'razon_social',
            'tipo_persona', 'municipio_dane',
            'saldo_cxp',
        ];
        $rows = collect($data['registros'] ?? [])->map(fn ($r) => [
            $r['tipo_documento'] ?? '',
            $r['identificacion'] ?? '',
            $r['dv'] ?? '',
            $r['razon_social'] ?? '',
            $r['tipo_persona'] ?? '',
            $r['municipio'] ?? '',
            $this->montoEntero($r['saldo_cxp'] ?? 0),
        ]);
        return $this->armarCsv($headers, $rows->all());
    }

    /**
     * Convierte un valor numérico a entero sin decimales (formato DIAN).
     * Los centavos se redondean al peso más cercano.
     */
    private function montoEntero(mixed $valor): string
    {
        return (string) (int) round((float) $valor);
    }

    /**
     * Une headers + filas en CSV pipe-delimited con BOM UTF-8.
     *
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
     * Construye una fila CSV. Escapa el delimitador en los valores
     * y normaliza saltos de línea.
     *
     * @param array<int|string, mixed> $row
     */
    private function fila(array $row): string
    {
        $cells = array_map(function ($cell): string {
            $s = (string) $cell;
            // Reemplazar el delimitador y saltos de línea con espacio
            $s = str_replace([self::DELIM, "\n", "\r"], ' ', $s);
            return $s;
        }, $row);
        return implode(self::DELIM, $cells);
    }
}
