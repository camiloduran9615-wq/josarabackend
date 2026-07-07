<?php

declare(strict_types=1);

namespace App\Services\Asiento;

use App\Models\Tenant\Asiento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Asigna consecutivos a asientos al ser aprobados.
 * Garantiza consecutividad sin saltos por (tipo_comprobante, año_fiscal)
 * con bloqueo pesimista (SELECT FOR UPDATE).
 *
 * Cumple Resolución DIAN 000042/2020 art. 11 y art. 56 Código de Comercio.
 */
class ConsecutivoAsientoService
{
    /**
     * Asigna número y año fiscal al asiento. Idempotente:
     * si el asiento ya tiene número, lo conserva.
     */
    public function asignar(Asiento $asiento): string
    {
        if (! empty($asiento->numero)) {
            return (string) $asiento->numero;
        }

        $año = (int) (
            $asiento->año_fiscal
            ?? CarbonImmutable::parse((string) $asiento->fecha)->year
        );
        $tipo = (string) $asiento->tipo_comprobante;

        return DB::transaction(function () use ($asiento, $año, $tipo): string {
            // Lock pesimista sobre la fila de control (o crear si no existe).
            $row = DB::table('consecutivos_asientos')
                ->where('tipo_comprobante', $tipo)
                ->where('año_fiscal', $año)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('consecutivos_asientos')->insert([
                    'tipo_comprobante'    => $tipo,
                    'año_fiscal'          => $año,
                    'ultimo_consecutivo'  => 1,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
                $consecutivo = 1;
            } else {
                $consecutivo = (int) $row->ultimo_consecutivo + 1;
                DB::table('consecutivos_asientos')
                    ->where('tipo_comprobante', $tipo)
                    ->where('año_fiscal', $año)
                    ->update([
                        'ultimo_consecutivo' => $consecutivo,
                        'updated_at'         => now(),
                    ]);
            }

            $numero = sprintf('%s-%d-%06d', $tipo, $año, $consecutivo);
            $asiento->forceFill([
                'numero'     => $numero,
                'año_fiscal' => $año,
            ])->save();

            return $numero;
        });
    }
}
