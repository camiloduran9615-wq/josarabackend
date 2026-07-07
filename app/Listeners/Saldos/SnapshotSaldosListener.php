<?php

declare(strict_types=1);

namespace App\Listeners\Saldos;

use App\Events\Periodo\PeriodoCerrado;
use App\Models\Tenant\CuentaSaldo;
use App\Models\Tenant\CuentaSaldoHistoricoCierre;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Engancha a `PeriodoCerrado`: copia todas las filas de `cuenta_saldos` del periodo
 * a `cuenta_saldos_historicos_cierre` con hash SHA-256 por fila.
 *
 * Una vez insertadas, el trigger PG `trg_csh_protect` impide UPDATE/DELETE — la
 * snapshot es inmutable de por vida (cumple DIAN 000042/2020 y Cód. Comercio art. 28).
 *
 * SÍNCRONO: el snapshot debe estar completo antes de marcar el periodo como `cerrado`.
 *
 * Periodo_codigo:
 *   - Mensual: 'YYYY-MM'  (ej '2026-05')
 *   - Anual:   'YYYY-FY'  (ej '2026-FY')
 *
 * El listener infiere si es cierre mensual o anual mirando el tipo del periodo.
 * La distinción mensual/anual se determina por el campo `tipo` del PeriodoContable
 * (heredado de la épica EPIC-002) — fallback a mensual si el campo no existe.
 */
final class SnapshotSaldosListener
{
    public function handle(PeriodoCerrado $event): void
    {
        $periodo = $event->periodo;

        $periodoCodigo = $this->resolverCodigoPeriodo($periodo);
        $cerradoAt     = new \DateTimeImmutable();
        $cerradoPor    = (string) $event->closer->id;

        // Procesar en chunks para tenants grandes (10k+ filas en un periodo)
        CuentaSaldo::query()
            ->where('periodo_id', $periodo->id)
            ->orderBy('id')
            ->chunkById(500, function ($saldos) use ($periodoCodigo, $cerradoAt, $cerradoPor): void {
                $batch = [];
                foreach ($saldos as $saldo) {
                    $batch[] = $this->buildSnapshotRow($saldo, $periodoCodigo, $cerradoAt, $cerradoPor);
                }
                if ($batch !== []) {
                    DB::table('cuenta_saldos_historicos_cierre')->insert($batch);
                }
            });
    }

    /**
     * @param  \App\Models\Tenant\PeriodoContable  $periodo
     */
    private function resolverCodigoPeriodo(object $periodo): string
    {
        $fechaInicio = $this->parsearFecha((string) ($periodo->fecha_inicio ?? ''));

        // Si el periodo tiene tipo anual/FY → código de año fiscal
        $tipo = property_exists($periodo, 'tipo') ? (string) $periodo->tipo : '';
        if ($tipo === 'anual' || $tipo === 'FY') {
            return sprintf('%04d-FY', (int) $fechaInicio->format('Y'));
        }

        return $fechaInicio->format('Y-m');
    }

    private function parsearFecha(string $valor): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($valor !== '' ? $valor : 'now');
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshotRow(
        CuentaSaldo $saldo,
        string $periodoCodigo,
        \DateTimeImmutable $cerradoAt,
        string $cerradoPor,
    ): array {
        $payload = [
            'cuenta_saldo_id'        => $saldo->id,
            'cuenta_contable_id'     => $saldo->cuenta_contable_id,
            'periodo_id'             => $saldo->periodo_id,
            'tercero_id'             => $saldo->tercero_id,
            'centro_costo_id'        => $saldo->centro_costo_id,
            'sucursal_id'            => $saldo->sucursal_id,
            'saldo_inicial_debito'   => $saldo->saldo_inicial_debito,
            'saldo_inicial_credito'  => $saldo->saldo_inicial_credito,
            'movimiento_debito'      => $saldo->movimiento_debito,
            'movimiento_credito'     => $saldo->movimiento_credito,
            'saldo_final_debito'     => $saldo->saldo_final_debito,
            'saldo_final_credito'    => $saldo->saldo_final_credito,
            'cerrado_at'             => $cerradoAt->format('Y-m-d H:i:sP'),
            'cerrado_por_user_id'    => $cerradoPor,
            'periodo_codigo'         => $periodoCodigo,
        ];

        $payload['hash_snapshot'] = hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        $payload['id']         = (string) Str::uuid();
        $payload['created_at'] = $cerradoAt->format('Y-m-d H:i:sP');

        return $payload;
    }
}
