<?php

declare(strict_types=1);

namespace App\Services\Conciliacion;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Servicio de conciliación bancaria.
 *
 * Funciones:
 *   1. importarCsv()  — parsea extracto CSV y graba lineas_extracto
 *   2. conciliarAuto() — cruza líneas del extracto con recibos_caja y comprobantes_egreso
 *   3. conciliarManual() — permite al usuario ligar manualmente
 *
 * Formato CSV esperado (compatible con bancos colombianos comunes):
 *   fecha,descripcion,referencia,debito,credito,saldo
 *   2026-01-05,CONSIGNACION CLIENTE,123456,0,500000,1500000
 */
class ConciliacionBancariaService
{
    /**
     * Importa un extracto CSV y crea el extracto bancario con sus líneas.
     *
     * @param array<string, mixed> $meta  banco, numero_cuenta, periodo_inicio, periodo_fin, saldo_inicial
     * @return array{extracto_id: string, lineas: int, errores: string[]}
     */
    public function importarCsv(UploadedFile $archivo, array $meta, string $userId): array
    {
        $extractoId = (string) Str::uuid();
        $errores    = [];
        $lineas     = 0;

        DB::transaction(function () use ($archivo, $meta, $extractoId, $userId, &$lineas, &$errores): void {
            // Crear cabecera del extracto
            DB::table('extractos_bancarios')->insert([
                'id'               => $extractoId,
                'banco'            => $meta['banco'],
                'numero_cuenta'    => $meta['numero_cuenta'],
                'periodo_inicio'   => $meta['periodo_inicio'],
                'periodo_fin'      => $meta['periodo_fin'],
                'saldo_inicial'    => (float) ($meta['saldo_inicial'] ?? 0),
                'saldo_final'      => 0,
                'archivo_nombre'   => $archivo->getClientOriginalName(),
                'estado'           => 'importado',
                'importado_por_id' => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Parsear CSV
            $contenido = file_get_contents($archivo->getRealPath());
            if ($contenido === false) {
                $errores[] = 'No se pudo leer el archivo.';
                return;
            }

            $filas  = array_map('str_getcsv', explode("\n", trim($contenido)));
            $header = array_map('strtolower', array_map('trim', $filas[0]));

            // Mapeo flexible de columnas
            $idx = [
                'fecha'       => array_search('fecha', $header, true)       ?: 0,
                'descripcion' => array_search('descripcion', $header, true)  ?: 1,
                'referencia'  => array_search('referencia', $header, true)   ?: 2,
                'debito'      => array_search('debito', $header, true)       ?: 3,
                'credito'     => array_search('credito', $header, true)      ?: 4,
                'saldo'       => array_search('saldo', $header, true)        ?: 5,
            ];

            $saldoActual = (float) ($meta['saldo_inicial'] ?? 0);
            $batch       = [];

            foreach (array_slice($filas, 1) as $nFila => $fila) {
                if (count($fila) < 4) {
                    continue;
                }

                try {
                    $fechaRaw  = trim($fila[$idx['fecha']] ?? '');
                    $fecha     = Carbon::createFromFormat('Y-m-d', $fechaRaw)?->toDateString()
                        ?? Carbon::parse($fechaRaw)->toDateString();

                    $debito  = (float) str_replace(['.', ',', '$', ' '], ['', '.', '', ''], trim($fila[$idx['debito']] ?? '0'));
                    $credito = (float) str_replace(['.', ',', '$', ' '], ['', '.', '', ''], trim($fila[$idx['credito']] ?? '0'));
                    $saldo   = isset($fila[$idx['saldo']]) && $fila[$idx['saldo']] !== ''
                        ? (float) str_replace(['.', ',', '$', ' '], ['', '.', '', ''], trim($fila[$idx['saldo']]))
                        : $saldoActual + $credito - $debito;

                    $saldoActual = $saldo;
                    $batch[]     = [
                        'id'                   => (string) Str::uuid(),
                        'extracto_id'          => $extractoId,
                        'fecha'                => $fecha,
                        'descripcion'          => mb_substr(trim($fila[$idx['descripcion']] ?? ''), 0, 300),
                        'referencia'           => mb_substr(trim($fila[$idx['referencia']] ?? ''), 0, 100),
                        'debito'               => $debito,
                        'credito'              => $credito,
                        'saldo'                => $saldo,
                        'estado_conciliacion'  => 'pendiente',
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                    $lineas++;
                } catch (\Throwable) {
                    $errores[] = "Fila {$nFila}: no se pudo parsear.";
                }
            }

            if (!empty($batch)) {
                // Insertar en chunks para no romper el límite de parámetros PDO
                foreach (array_chunk($batch, 100) as $chunk) {
                    DB::table('lineas_extracto')->insert($chunk);
                }
            }

            // Actualizar saldo final del extracto
            DB::table('extractos_bancarios')
                ->where('id', $extractoId)
                ->update(['saldo_final' => $saldoActual, 'updated_at' => now()]);
        });

        return ['extracto_id' => $extractoId, 'lineas' => $lineas, 'errores' => $errores];
    }

    /**
     * Conciliación automática: cruza líneas del extracto con recibos de caja
     * y comprobantes de egreso del mismo período, buscando coincidencias por
     * fecha (±3 días) y monto exacto.
     *
     * @return array{conciliadas: int, pendientes: int}
     */
    public function conciliarAuto(string $extractoId): array
    {
        $lineas     = DB::table('lineas_extracto')
            ->where('extracto_id', $extractoId)
            ->where('estado_conciliacion', 'pendiente')
            ->get();

        $conciliadas = 0;

        foreach ($lineas as $linea) {
            // Buscar recibo de caja con mismo valor y fecha ±3 días
            if ($linea->credito > 0) {
                $recibo = DB::table('recibos_caja')
                    ->where('valor_recibido', $linea->credito)
                    ->where('estado', '!=', 'anulado')
                    ->whereBetween('fecha', [
                        Carbon::parse($linea->fecha)->subDays(3)->toDateString(),
                        Carbon::parse($linea->fecha)->addDays(3)->toDateString(),
                    ])
                    ->whereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('conciliaciones')
                            ->where('origen_type', 'ReciboCaja')
                            ->whereColumn('origen_id', 'recibos_caja.id');
                    })
                    ->first();

                if ($recibo !== null) {
                    DB::table('conciliaciones')->insert([
                        'id'                  => (string) Str::uuid(),
                        'linea_extracto_id'   => $linea->id,
                        'origen_type'         => 'ReciboCaja',
                        'origen_id'           => $recibo->id,
                        'tipo_conciliacion'   => 'automatica',
                        'diferencia'          => 0,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);

                    DB::table('lineas_extracto')
                        ->where('id', $linea->id)
                        ->update(['estado_conciliacion' => 'conciliado', 'updated_at' => now()]);

                    $conciliadas++;
                    continue;
                }
            }

            // Buscar comprobante de egreso con mismo valor y fecha ±3 días
            if ($linea->debito > 0) {
                $egreso = DB::table('comprobantes_egreso')
                    ->where('valor_pagado', $linea->debito)
                    ->whereBetween('fecha', [
                        Carbon::parse($linea->fecha)->subDays(3)->toDateString(),
                        Carbon::parse($linea->fecha)->addDays(3)->toDateString(),
                    ])
                    ->whereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('conciliaciones')
                            ->where('origen_type', 'ComprobanteEgreso')
                            ->whereColumn('origen_id', 'comprobantes_egreso.id');
                    })
                    ->first();

                if ($egreso !== null) {
                    DB::table('conciliaciones')->insert([
                        'id'                  => (string) Str::uuid(),
                        'linea_extracto_id'   => $linea->id,
                        'origen_type'         => 'ComprobanteEgreso',
                        'origen_id'           => $egreso->id,
                        'tipo_conciliacion'   => 'automatica',
                        'diferencia'          => 0,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);

                    DB::table('lineas_extracto')
                        ->where('id', $linea->id)
                        ->update(['estado_conciliacion' => 'conciliado', 'updated_at' => now()]);

                    $conciliadas++;
                }
            }
        }

        $pendientes = DB::table('lineas_extracto')
            ->where('extracto_id', $extractoId)
            ->where('estado_conciliacion', 'pendiente')
            ->count();

        return ['conciliadas' => $conciliadas, 'pendientes' => $pendientes];
    }

    /**
     * Conciliación manual: el usuario elige qué documento liga a qué línea.
     */
    public function conciliarManual(string $lineaId, string $origenType, string $origenId, ?string $nota, string $userId): void
    {
        DB::transaction(function () use ($lineaId, $origenType, $origenId, $nota, $userId): void {
            // Evitar duplicados
            $existe = DB::table('conciliaciones')->where('linea_extracto_id', $lineaId)->exists();
            if ($existe) {
                throw new \RuntimeException('Esta línea ya está conciliada.');
            }

            DB::table('conciliaciones')->insert([
                'id'                  => (string) Str::uuid(),
                'linea_extracto_id'   => $lineaId,
                'origen_type'         => $origenType,
                'origen_id'           => $origenId,
                'tipo_conciliacion'   => 'manual',
                'diferencia'          => 0,
                'nota'                => $nota,
                'conciliado_por_id'   => $userId,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            DB::table('lineas_extracto')
                ->where('id', $lineaId)
                ->update(['estado_conciliacion' => 'conciliado', 'updated_at' => now()]);
        });
    }
}
