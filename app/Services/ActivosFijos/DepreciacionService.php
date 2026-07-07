<?php

declare(strict_types=1);

namespace App\Services\ActivosFijos;

use App\Models\Tenant\ActivoFijo;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\DepreciacionMensual;
use App\Services\Contabilizacion\ContabilizadorService;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de depreciación mensual de activos fijos (NIC 16).
 *
 * Genera UN solo asiento contable consolidado por mes con todas las
 * depreciaciones de activos en estado 'activo'. Estructura:
 *
 *   DÉBITO  516005/etc Gasto depreciación (por categoría)
 *   CRÉDITO 159205/etc Depreciación acumulada (por categoría)
 *
 * Idempotente:
 *   - tabla `depreciaciones_mensuales` tiene UNIQUE (activo_fijo_id, anio, mes)
 *   - cada activo se salta si ya tiene movimiento del mes en cuestión
 *
 * Política contable:
 *   - El método es LÍNEA RECTA: valor mensual = (costo - residual) / vida_util
 *   - Solo deprecia activos en estado='activo'
 *   - Solo deprecia si la fecha_inicio_depreciacion <= último día del mes
 *   - Detiene cuando depreciación_acumulada >= (costo - valor_residual)
 */
class DepreciacionService
{
    public function __construct(
        private readonly ContabilizadorService $contabilizador,
    ) {}

    /**
     * Ejecuta la depreciación del mes para todos los activos elegibles.
     *
     * @return array{
     *   activos_procesados: int,
     *   activos_saltados: int,
     *   total_depreciado: float,
     *   asiento_id: ?string,
     * }
     */
    public function depreciarMes(int $anio, int $mes, string $createdById): array
    {
        if ($mes < 1 || $mes > 12) {
            throw new \InvalidArgumentException("Mes inválido: {$mes}");
        }

        $ultimoDia = \Carbon\CarbonImmutable::create($anio, $mes, 1)->endOfMonth();

        return DB::transaction(function () use ($anio, $mes, $ultimoDia, $createdById): array {
            $activos = ActivoFijo::query()
                ->where('estado', ActivoFijo::ESTADO_ACTIVO)
                ->whereDate('fecha_adquisicion', '<=', $ultimoDia->toDateString())
                ->get();

            $lineasAsiento = [];
            // Agrupamos los DB de gasto y CR de acumulada por cuenta para
            // consolidar el asiento (una línea por cuenta, no por activo).
            $debitosPorCuenta  = [];  // cuenta_id → valor acumulado
            $creditosPorCuenta = [];

            $procesados   = 0;
            $saltados     = 0;
            $totalDeprec  = 0.0;
            $movimientosBuffer = []; // crearemos después de tener asiento_id

            foreach ($activos as $activo) {
                // ¿Ya está depreciado este mes?
                $existe = DepreciacionMensual::where('activo_fijo_id', $activo->id)
                    ->where('anio', $anio)
                    ->where('mes', $mes)
                    ->exists();
                if ($existe) {
                    $saltados++;
                    continue;
                }

                // ¿La fecha de inicio de depreciación ya pasó?
                $inicioDep = $activo->fecha_inicio_depreciacion ?? $activo->fecha_adquisicion;
                if ($inicioDep === null || $inicioDep->greaterThan($ultimoDia)) {
                    $saltados++;
                    continue;
                }

                // ¿Aún hay base por depreciar?
                $baseDepreciable = (float) $activo->costo_adquisicion - (float) $activo->valor_residual;
                $acumuladaActual = (float) $activo->depreciacion_acumulada;
                $pendiente       = $baseDepreciable - $acumuladaActual;

                if ($pendiente <= 0.01) {
                    $saltados++;
                    continue;
                }

                // Calcular cuota: la mensual, o lo que quede si es el último mes
                $cuotaMensual = $activo->depreciacionMensual();
                $valor = min($cuotaMensual, $pendiente);
                $valor = round($valor, 2);

                if ($valor <= 0) {
                    $saltados++;
                    continue;
                }

                $procesados++;
                $totalDeprec += $valor;

                $debitosPorCuenta[$activo->cuenta_gasto_depreciacion_id] =
                    ($debitosPorCuenta[$activo->cuenta_gasto_depreciacion_id] ?? 0.0) + $valor;
                $creditosPorCuenta[$activo->cuenta_depreciacion_acumulada_id] =
                    ($creditosPorCuenta[$activo->cuenta_depreciacion_acumulada_id] ?? 0.0) + $valor;

                $movimientosBuffer[] = [
                    'activo'    => $activo,
                    'valor'     => $valor,
                ];
            }

            if ($procesados === 0) {
                return [
                    'activos_procesados' => 0,
                    'activos_saltados'   => $saltados,
                    'total_depreciado'   => 0.0,
                    'asiento_id'         => null,
                ];
            }

            // Construir asiento consolidado (debitos y créditos por cuenta)
            $lineasAsiento = [];
            foreach ($debitosPorCuenta as $cuentaId => $valor) {
                $lineasAsiento[] = [
                    'cuenta_contable_id' => $cuentaId,
                    'debito'             => round($valor, 2),
                    'credito'            => 0.0,
                    'descripcion'        => "Depreciación mensual {$anio}-" . str_pad((string) $mes, 2, '0', STR_PAD_LEFT),
                ];
            }
            foreach ($creditosPorCuenta as $cuentaId => $valor) {
                $lineasAsiento[] = [
                    'cuenta_contable_id' => $cuentaId,
                    'debito'             => 0.0,
                    'credito'            => round($valor, 2),
                    'descripcion'        => "Depreciación acumulada {$anio}-" . str_pad((string) $mes, 2, '0', STR_PAD_LEFT),
                ];
            }

            // Necesitamos un "origen" para el ContabilizadorService.
            // Usamos el primer activo como ancla simbólica (la idempotencia real
            // está en `depreciaciones_mensuales` UNIQUE constraint).
            $origenAncla = $movimientosBuffer[0]['activo'];

            // Pero ContabilizadorService usa polimórfico origen_id/origen_type
            // y el unique parcial unique_asiento_origen prohíbe dos asientos
            // normales con el mismo origen. Si depreciamos varios meses, todos
            // usarían el mismo origen → conflicto.
            // Solución: usamos un identificador distinto cada mes (no via
            // contabilizador). Llamamos al contabilizador con un sentinel que
            // sabemos no chocará — pero más simple: bypass directo aquí.

            $fecha = $ultimoDia->toDateString();

            $asiento = $this->crearAsientoDepreciacion(
                fecha:        $fecha,
                descripcion:  "Depreciación mensual {$anio}-" . str_pad((string) $mes, 2, '0', STR_PAD_LEFT),
                lineas:       $lineasAsiento,
                createdById:  $createdById,
                origen:       $origenAncla,
                anio:         $anio,
                mes:          $mes,
            );

            // Registrar movimientos individuales y actualizar acumulada
            foreach ($movimientosBuffer as $mov) {
                $activo = $mov['activo'];
                $valor  = $mov['valor'];

                $activo->depreciacion_acumulada = (float) $activo->depreciacion_acumulada + $valor;
                $activo->ultima_depreciacion = $fecha;
                $activo->save();

                DepreciacionMensual::create([
                    'activo_fijo_id'                   => $activo->id,
                    'asiento_id'                       => $asiento->id,
                    'anio'                             => $anio,
                    'mes'                              => $mes,
                    'valor_depreciacion'               => $valor,
                    'depreciacion_acumulada_al_cierre' => $activo->depreciacion_acumulada,
                ]);
            }

            return [
                'activos_procesados' => $procesados,
                'activos_saltados'   => $saltados,
                'total_depreciado'   => round($totalDeprec, 2),
                'asiento_id'         => $asiento->id,
            ];
        });
    }

    /**
     * Crea el asiento contable de depreciación bypassing el ContabilizadorService
     * porque éste fuerza unique por (origen_type, origen_id) y queremos múltiples
     * asientos de depreciación (uno por mes) sobre el mismo "origen ancla".
     *
     * @param array<int, array<string, mixed>> $lineas
     */
    private function crearAsientoDepreciacion(
        string $fecha,
        string $descripcion,
        array $lineas,
        string $createdById,
        ActivoFijo $origen,
        int $anio,
        int $mes,
    ): Asiento {
        $periodo = \App\Models\Tenant\PeriodoContable::actual(\Carbon\CarbonImmutable::parse($fecha));
        if ($periodo === null) {
            throw new \RuntimeException("No hay periodo contable abierto para la fecha {$fecha}.");
        }

        /** @var Asiento $asiento */
        $asiento = (new Asiento())->forceFill([
            'fecha'             => $fecha,
            'periodo_id'        => $periodo->id,
            'tipo_comprobante'  => 'DP', // Depreciación
            'numero_documento'  => sprintf('DEP-%04d-%02d', $anio, $mes),
            'estado'            => Asiento::ESTADO_APROBADO,
            'tipo_movimiento'   => Asiento::TIPO_NORMAL,
            'descripcion'       => $descripcion,
            'comprobante'       => $descripcion,
            // NO usamos origen polimórfico aquí porque:
            //  1. El asiento de depreciación es CONSOLIDADO (varios activos en uno).
            //  2. El índice unique_asiento_origen prohibiría tener tanto el asiento
            //     de adquisición (CM, también origen=ActivoFijo) como los DP mensuales
            //     apuntando al mismo activo.
            //  3. La idempotencia ya está garantizada por uq_depreciacion_activo_mes
            //     en la tabla depreciaciones_mensuales (PK lógica: activo+año+mes).
            'origen_type'       => null,
            'origen_id'         => null,
            'created_by_id'     => $createdById,
            'approved_by_id'    => $createdById,
            'approved_at'       => now(),
        ]);
        $asiento->save();

        foreach ($lineas as $l) {
            \App\Models\Tenant\AsientoLinea::query()->create([
                'asiento_id'           => $asiento->id,
                'cuenta_id'            => $l['cuenta_contable_id'],
                'debito'               => (float) ($l['debito'] ?? 0),
                'credito'              => (float) ($l['credito'] ?? 0),
                'descripcion_item'     => $l['descripcion'] ?? null,
            ]);
        }

        // Asignar consecutivo (vía servicio interno del módulo Asiento)
        app(\App\Services\Asiento\ConsecutivoAsientoService::class)->asignar($asiento);

        return $asiento->refresh();
    }
}
