<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MunicipioDane;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DaneMunicipioSyncService
{
    /** @var list<string> */
    private array $allowedHosts;

    public function __construct()
    {
        $this->allowedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.dane.allowed_hosts', 'www.dane.gov.co,dane.gov.co,www.datos.gov.co,datos.gov.co')),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{processed:int, inserted:int, updated:int, skipped:int, total:int, source:string|null}
     */
    public function syncRows(array $rows, ?string $source = null): array
    {
        $processed = 0;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row);
            if ($normalized === null) {
                $skipped++;
                continue;
            }

            $municipio = MunicipioDane::query()->updateOrCreate(
                ['codigo_dane' => $normalized['codigo_dane']],
                [
                    'municipio_nombre'    => $normalized['municipio_nombre'],
                    'departamento_dane'   => $normalized['departamento_dane'],
                    'departamento_nombre' => $normalized['departamento_nombre'],
                    'region'              => $normalized['region'],
                    'activo'              => true,
                ],
            );

            $municipio->wasRecentlyCreated ? $inserted++ : $updated++;
            $processed++;
        }

        $result = [
            'processed' => $processed,
            'inserted'  => $inserted,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'total'     => MunicipioDane::query()->count(),
            'source'    => $source,
        ];

        Log::info('Municipios DANE sincronizados', $result);

        return $result;
    }

    /**
     * @return array{processed:int, inserted:int, updated:int, skipped:int, total:int, source:string|null}
     */
    public function syncFromConfiguredSource(): array
    {
        $url = (string) config('services.dane.divipola_url', '');
        if ($url === '') {
            throw new RuntimeException('No hay fuente DANE configurada. Define DANE_DIVIPOLA_URL en el entorno.');
        }

        $this->assertAllowedUrl($url);

        $response = Http::timeout((int) config('services.dane.timeout', 20))
            ->retry(2, 300)
            ->accept('*/*')
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('La fuente DANE no respondió correctamente. HTTP '.$response->status());
        }

        $body = $response->body();
        $maxBytes = (int) config('services.dane.max_bytes', 5242880);
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('La respuesta DANE supera el tamaño máximo permitido.');
        }

        $rows = $this->parseBody($body, (string) $response->header('Content-Type'));

        return $this->syncRows($rows, $url);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseBody(string $body, string $contentType = ''): array
    {
        $trimmed = ltrim($body);
        if (str_contains($contentType, 'json') || str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $json = json_decode($body, true);
            if (! is_array($json)) {
                throw new RuntimeException('La fuente DANE no contiene JSON válido.');
            }

            $rows = $json['data'] ?? $json['results'] ?? $json;
            if (! is_array($rows)) {
                throw new RuntimeException('La fuente DANE JSON no contiene una lista de municipios.');
            }

            return array_values(array_filter($rows, 'is_array'));
        }

        return $this->parseCsv($body);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('No fue posible preparar el CSV DANE.');
        }

        fwrite($handle, $body);
        rewind($handle);

        $headers = null;
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',')) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(fn ($value) => $this->key((string) $value), $line);
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $line[$index] ?? null;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{codigo_dane:string, municipio_nombre:string, departamento_dane:string, departamento_nombre:string, region:?string}|null
     */
    private function normalizeRow(array $row): ?array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[$this->key((string) $key)] = is_scalar($value) ? trim((string) $value) : null;
        }

        $codigo = $this->first($normalized, [
            'codigo_dane', 'codigo', 'codigodane', 'cod_municipio', 'codigomunicipio', 'codmpio', 'mpio_ccdgo', 'divipola',
        ]);
        $municipio = $this->first($normalized, [
            'municipio_nombre', 'nombre', 'municipio', 'nombre_municipio', 'nom_municipio', 'mpio_cnmbr', 'entidad_territorial',
        ]);
        $deptoCodigo = $this->first($normalized, [
            'departamento_dane', 'departamento_codigo', 'cod_departamento', 'codigo_departamento', 'dpto_ccdgo', 'coddepto',
        ]);
        $deptoNombre = $this->first($normalized, [
            'departamento_nombre', 'departamento', 'nombre_departamento', 'nom_departamento', 'dpto_cnmbr',
        ]);
        $region = $this->first($normalized, ['region', 'zona', 'region_nombre']);

        if ($codigo === null || $municipio === null || $deptoCodigo === null || $deptoNombre === null) {
            return null;
        }

        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        $deptoCodigo = preg_replace('/\D+/', '', $deptoCodigo) ?? '';

        if (strlen($codigo) < 5 || strlen($deptoCodigo) !== 2) {
            return null;
        }

        return [
            'codigo_dane'         => str_pad(substr($codigo, 0, 8), 5, '0', STR_PAD_LEFT),
            'municipio_nombre'    => $municipio,
            'departamento_dane'   => str_pad($deptoCodigo, 2, '0', STR_PAD_LEFT),
            'departamento_nombre' => $deptoNombre,
            'region'              => $region !== null && $region !== '' ? $region : null,
        ];
    }

    /**
     * @param array<string, string|null> $row
     * @param list<string> $keys
     */
    private function first(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function key(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '' || ! in_array($host, $this->allowedHosts, true)) {
            throw new RuntimeException('Fuente DANE no permitida. Usa un origen HTTPS oficial configurado por el servidor.');
        }
    }
}
