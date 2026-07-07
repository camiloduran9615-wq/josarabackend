<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\LiquidacionNomina;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use Illuminate\Support\Facades\DB;

/**
 * Genera el asiento contable de nómina al aprobar la liquidación.
 *
 * Estructura del asiento (partida doble Decreto 2650 / PUC Col.):
 *
 *   DÉBITO (gasto)
 *     510506 Sueldos y salarios          ← salario + extras + bonif + comisión
 *     510527 Auxilio de transporte       ← si aplica
 *     510530 Cesantías (gasto)
 *     510533 Intereses cesantías
 *     510536 Prima de servicios
 *     510539 Vacaciones
 *     510568 Aportes salud empleador     ← si NO exonerado (Ley 1607)
 *     510569 Aportes pensión empleador
 *     510548 Aportes ARL
 *     510570 Caja Compensación
 *     510572 Aportes ICBF                ← si NO exonerado
 *     510575 Aportes SENA                ← si NO exonerado
 *
 *   CRÉDITO (pasivo)
 *     250505 Salarios por pagar          ← neto a pagar
 *     237005 Aportes salud por pagar     ← salud emp + salud empleador
 *     237010 Aportes pensión por pagar   ← pensión emp + pensión empleador
 *     237015 ARL por pagar
 *     237020 SENA por pagar
 *     237025 ICBF por pagar
 *     237030 CCF por pagar
 *     251005 Cesantías consolidadas (pasivo)
 *     251505 Intereses cesantías (pasivo)
 *     252005 Prima de servicios (pasivo)
 *     252505 Vacaciones consolidadas (pasivo)
 *
 * Idempotencia: si ya existe asiento con origen_id=liquidacion.id, lo retorna.
 * Maneja exoneración Ley 1607 — sólo se incluyen líneas con valor > 0.
 */
class AsientoNominaService
{
    public function __construct(
        private readonly ContabilizadorService $contabilizador,
    ) {}

    /**
     * Genera (o retorna existente) el asiento contable de la liquidación.
     *
     * @throws ParametrizacionFaltanteException si falta alguna clave nomina.cuenta_*
     */
    public function generar(LiquidacionNomina $liquidacion, string $createdById): Asiento
    {
        $existente = $this->contabilizador->asientoExistenteDe($liquidacion);
        if ($existente !== null) {
            return $existente;
        }

        return DB::transaction(function () use ($liquidacion, $createdById): Asiento {
            $liquidacion->loadMissing(['lineas.concepto', 'periodo', 'empleado']);

            $lineasAsiento = $this->construirLineas($liquidacion);

            $fecha = $liquidacion->periodo?->fecha_fin?->toDateString() ?? now()->toDateString();
            $empNombre = trim(($liquidacion->empleado?->primer_nombre ?? '')
                . ' ' . ($liquidacion->empleado?->primer_apellido ?? ''));

            $asiento = $this->contabilizador->contabilizar([
                'fecha'            => $fecha,
                'tipo_comprobante' => 'NOM',
                'descripcion'      => "Nómina — {$empNombre} — Periodo {$liquidacion->periodo?->codigo}",
                'numero_documento' => 'NOM-' . substr($liquidacion->id, 0, 8),
                'origen'           => $liquidacion,
                'created_by_id'    => $createdById,
                'lineas'           => $lineasAsiento,
            ]);

            $liquidacion->update(['asiento_id' => $asiento->id]);

            return $asiento;
        });
    }

    /**
     * Construye las líneas del asiento a partir de las líneas de liquidación.
     * Agrega importes por subtipo y resuelve cada cuenta vía parametrización.
     *
     * Garantiza partida doble:
     *   ∑ DÉBITOS = total_devengado (parte empleado) + provisiones + aportes empleador
     *   ∑ CRÉDITOS = (total_devengado − salud_emp − pensión_emp) (a 250505)
     *              + salud_emp + pensión_emp + aportes empleador (a 237xxx)
     *              + provisiones consolidadas (a 251xxx/252xxx)
     *
     * @return array<int, array<string, mixed>>
     */
    private function construirLineas(LiquidacionNomina $liq): array
    {
        // ── Acumular por categoría ──────────────────────────────────────────
        $auxTransporte    = 0.0;
        $horasExtra       = 0.0;
        $gastoCesantias   = 0.0;
        $gastoIntCes      = 0.0;
        $gastoPrima       = 0.0;
        $gastoVacaciones  = 0.0;
        $saludEmpleado    = 0.0;
        $pensionEmpleado  = 0.0;
        $gastoSaludEmp    = 0.0;
        $gastoPensionEmp  = 0.0;
        $gastoArl         = 0.0;
        $gastoCcf         = 0.0;
        $gastoSena        = 0.0;
        $gastoIcbf        = 0.0;

        foreach ($liq->lineas as $linea) {
            $codigo  = $linea->concepto?->codigo ?? '';
            $subtipo = $linea->concepto?->subtipo ?? '';
            $valor   = (float) $linea->valor_total;

            if ($linea->tipo === 'devengado') {
                match (true) {
                    $codigo === 'AUX_TRANSPORTE'                              => $auxTransporte += $valor,
                    in_array($subtipo, ['hora_extra', 'recargo'], true)       => $horasExtra += $valor,
                    $subtipo === 'cesantia' && $codigo === 'CESANTIAS'        => $gastoCesantias += $valor,
                    $subtipo === 'cesantia' && $codigo === 'INT_CESANTIAS'    => $gastoIntCes += $valor,
                    $subtipo === 'prima'                                      => $gastoPrima += $valor,
                    $subtipo === 'vacacion'                                   => $gastoVacaciones += $valor,
                    default                                                   => null, // resto se infiere de total_devengado
                };
                continue;
            }

            if ($linea->tipo === 'deduccion') {
                match ($subtipo) {
                    'salud'   => $saludEmpleado   += $valor,
                    'pension' => $pensionEmpleado += $valor,
                    default   => null, // embargo/libranza/retefuente quedan implícitas en el neto
                };
                continue;
            }

            if ($linea->tipo === 'aporte_empleador') {
                match ($subtipo) {
                    'salud_emp'   => $gastoSaludEmp   += $valor,
                    'pension_emp' => $gastoPensionEmp += $valor,
                    'arl'         => $gastoArl        += $valor,
                    'ccf'         => $gastoCcf        += $valor,
                    'sena'        => $gastoSena       += $valor,
                    'icbf'        => $gastoIcbf       += $valor,
                    default       => null,
                };
            }
        }

        // ── Derivar gasto base de sueldos a partir del total_devengado ──────
        // total_devengado guardado por el liquidador NO incluye provisiones.
        // Lo que no es aux. transporte ni horas extra cae en 510506.
        $totalDevengado = (float) $liq->total_devengado;
        $sueldos = round($totalDevengado - $auxTransporte - $horasExtra, 2);
        if ($sueldos < 0) {
            $sueldos = 0.0;
        }

        // El crédito a 250505 absorbe el neto + cualquier "otra deducción"
        // (embargo, libranza, retefuente) — todas son pasivos a terceros que
        // por simplicidad consolidamos en la misma cuenta. Esto preserva la
        // partida doble: 250505 = total_devengado − salud_emp − pensión_emp.
        $saldoPorPagar = round($totalDevengado - $saludEmpleado - $pensionEmpleado, 2);

        $empNombre = trim(($liq->empleado?->primer_nombre ?? '')
            . ' ' . ($liq->empleado?->primer_apellido ?? ''));
        $terceroId = $liq->empleado?->tercero_id;

        $lineas = [];

        // ── DÉBITOS (gastos) ─────────────────────────────────────────────────
        $this->pushDebito($lineas, 'nomina.cuenta_sueldos', $sueldos, "Sueldos — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_horas_extra', $horasExtra, "Horas extra y recargos — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_auxilio_transporte', $auxTransporte, "Aux. transporte — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_cesantias', $gastoCesantias, "Cesantías — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_int_cesantias', $gastoIntCes, "Intereses cesantías — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_prima', $gastoPrima, "Prima — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_vacaciones', $gastoVacaciones, "Vacaciones — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_salud_empleador', $gastoSaludEmp, "Salud empleador — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_pension_empleador', $gastoPensionEmp, "Pensión empleador — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_arl', $gastoArl, "ARL — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_ccf', $gastoCcf, "Caja Compensación — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_sena', $gastoSena, "SENA — {$empNombre}");
        $this->pushDebito($lineas, 'nomina.cuenta_gasto_icbf', $gastoIcbf, "ICBF — {$empNombre}");

        // ── CRÉDITOS (pasivos) ───────────────────────────────────────────────
        $this->pushCredito($lineas, 'nomina.cuenta_salarios_por_pagar', $saldoPorPagar, "Salarios por pagar — {$empNombre}", $terceroId);

        // Pasivos consolidados a terceros — la cuenta 237xxx acumula aporte
        // empleado + aporte empleador en la misma subcuenta.
        $this->pushCredito($lineas, 'nomina.cuenta_aporte_salud', $saludEmpleado + $gastoSaludEmp, "Aportes salud por pagar — {$empNombre}");
        $this->pushCredito($lineas, 'nomina.cuenta_aporte_pension', $pensionEmpleado + $gastoPensionEmp, "Aportes pensión por pagar — {$empNombre}");
        $this->pushCredito($lineas, 'nomina.cuenta_aporte_arl', $gastoArl, "ARL por pagar — {$empNombre}");
        $this->pushCredito($lineas, 'nomina.cuenta_caja_compensacion', $gastoCcf, "Caja Compensación por pagar — {$empNombre}");
        $this->pushCredito($lineas, 'nomina.cuenta_parafiscal_sena', $gastoSena, "SENA por pagar — {$empNombre}");
        $this->pushCredito($lineas, 'nomina.cuenta_parafiscal_icbf', $gastoIcbf, "ICBF por pagar — {$empNombre}");

        // Provisiones laborales (pasivos consolidados)
        $this->pushCredito($lineas, 'nomina.cuenta_cesantias', $gastoCesantias, "Cesantías por pagar — {$empNombre}", $terceroId);
        $this->pushCredito($lineas, 'nomina.cuenta_intereses_cesantias', $gastoIntCes, "Intereses cesantías por pagar — {$empNombre}", $terceroId);
        $this->pushCredito($lineas, 'nomina.cuenta_prima', $gastoPrima, "Prima por pagar — {$empNombre}", $terceroId);
        $this->pushCredito($lineas, 'nomina.cuenta_vacaciones', $gastoVacaciones, "Vacaciones por pagar — {$empNombre}", $terceroId);

        return $lineas;
    }

    /**
     * @param array<int, array<string, mixed>> $lineas
     */
    private function pushDebito(array &$lineas, string $clave, float $valor, string $desc, ?string $terceroId = null): void
    {
        if ($valor <= 0.0) {
            return;
        }

        $cuenta = $this->contabilizador->cuenta($clave);
        $lineas[] = [
            'cuenta_contable_id' => $cuenta->id,
            'debito'             => round($valor, 2),
            'credito'            => 0.0,
            'descripcion'        => $desc,
            'tercero_id'         => $terceroId,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lineas
     */
    private function pushCredito(array &$lineas, string $clave, float $valor, string $desc, ?string $terceroId = null): void
    {
        if ($valor <= 0.0) {
            return;
        }

        $cuenta = $this->contabilizador->cuenta($clave);
        $lineas[] = [
            'cuenta_contable_id' => $cuenta->id,
            'debito'             => 0.0,
            'credito'            => round($valor, 2),
            'descripcion'        => $desc,
            'tercero_id'         => $terceroId,
        ];
    }
}
