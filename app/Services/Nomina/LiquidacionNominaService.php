<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Tenant\ConceptoNomina;
use App\Models\Tenant\ContratoLaboral;
use App\Models\Tenant\Empleado;
use App\Models\Tenant\LiquidacionLinea;
use App\Models\Tenant\LiquidacionNomina;
use App\Models\Tenant\PeriodoNomina;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de liquidación de nómina — Ley 100/1993 + CST Colombia.
 *
 * Tarifas 2026 (hardcodeadas solo como constantes — cambiar vía config en futuro):
 *   Salud empleado   : 4%  de IBC
 *   Salud empleador  : 8.5% de IBC
 *   Pensión empleado : 4%  de IBC
 *   Pensión empleador: 12% de IBC
 *   ARL              : 0.522% – 6.960% según riesgo
 *   SENA             : 2%  de nómina (solo >10 SMMLV)
 *   ICBF             : 3%  de nómina (solo >10 SMMLV)
 *   Caja Compensación: 4%  de nómina
 *   Prima semestral  : salario / 360 × días (cada 6 meses)
 *   Cesantías        : salario / 360 × días
 *   Int. cesantías   : cesantías × 12% / año
 *   Vacaciones       : salario / 730 × días (15 días hábiles / año)
 */
class LiquidacionNominaService
{
    // Tarifas 2026 SMMLV = $1.423.500
    private const SMMLV_2026        = 1_423_500.0;
    private const AUX_TRANSPORTE_2026 = 200_000.0;     // Decreto MinTrabajo 2026
    private const AUX_TRANSPORTE_TOPE_SMMLV = 2;       // Aplica si salario <= 2 SMMLV
    private const SALUD_EMPLEADO    = 0.04;
    private const SALUD_EMPLEADOR   = 0.085;
    private const PENSION_EMPLEADO  = 0.04;
    private const PENSION_EMPLEADOR = 0.12;
    private const ARL_RIESGO_I      = 0.00522;   // nivel I (oficina)
    private const ARL_RIESGO_V      = 0.06960;   // nivel V (alto riesgo)
    private const CAJA_COMPENSACION = 0.04;
    private const SENA              = 0.02;
    private const ICBF              = 0.03;
    private const UMBRAL_PARAFISCAL = 10;         // SMMLV — exento si nómina <= 10 SMMLV
    private const CESANTIAS_FACTOR  = 0.0833;     // 8.33% sobre devengado (salario + aux. transporte)
    private const PRIMA_FACTOR      = 0.0833;     // 8.33% sobre devengado
    private const VACACIONES_FACTOR = 0.0417;     // 4.17% sobre salario (sin aux. transporte)
    private const INT_CESANTIAS_ANUAL = 0.12;     // 12% anual sobre cesantías

    /**
     * Liquida un empleado en un periodo dado.
     * Calcula devengados básicos + aportes de seguridad social obligatorios.
     *
     * @param array<string, mixed> $extras  Ítems adicionales: horas_extra, bonificaciones, etc.
     */
    public function liquidar(
        Empleado $empleado,
        PeriodoNomina $periodo,
        ContratoLaboral $contrato,
        array $extras = [],
    ): LiquidacionNomina {
        return DB::transaction(function () use ($empleado, $periodo, $contrato, $extras) {
            // Idempotencia: si ya existe, retornamos la existente
            $existente = LiquidacionNomina::where('periodo_nomina_id', $periodo->id)
                ->where('empleado_id', $empleado->id)
                ->first();
            if ($existente !== null) {
                return $existente;
            }

            $salario    = (float) $contrato->salario_basico;
            $dias       = $contrato->dias_trabajo;        // 30 por defecto
            $altoRiesgo = $contrato->alto_riesgo;

            // ── Salario del periodo (proporcional si < 30 días) ──────────
            $salarioPeriodo = round($salario * ($dias / 30), 4);

            // ── Auxilio de transporte (Ley 15/1959, CST art. 230) ────────
            // Obligatorio si el salario es <= 2 SMMLV. Es devengado pero
            // NO es base para salud/pensión. SÍ es base para cesantías/prima.
            $auxTransporte = 0.0;
            if ($salario <= self::AUX_TRANSPORTE_TOPE_SMMLV * self::SMMLV_2026) {
                $auxTransporte = round(self::AUX_TRANSPORTE_2026 * ($dias / 30), 4);
            }

            // ── Horas extra (enviadas en $extras) ────────────────────────
            $horasExtra        = (float) ($extras['horas_extra_diurnas']  ?? 0);
            $horasExtraNocturna = (float) ($extras['horas_extra_nocturnas'] ?? 0);
            $valorHoraExtra    = $salario / 240;
            $totalHorasExtra   = round(
                ($horasExtra * $valorHoraExtra * 1.25) +
                ($horasExtraNocturna * $valorHoraExtra * 1.75),
                4
            );

            // ── IBC (Ingreso Base de Cotización) ────────────────────────
            // IBC mínimo = SMMLV; máximo = 25 SMMLV.
            // Aux. transporte NO entra en IBC.
            $ibc = max(self::SMMLV_2026, min(
                $salarioPeriodo + $totalHorasExtra,
                25 * self::SMMLV_2026,
            ));

            // ── Aportes empleado (deducciones) ──────────────────────────
            $saludEmp   = round($ibc * self::SALUD_EMPLEADO, 4);
            $pensionEmp = round($ibc * self::PENSION_EMPLEADO, 4);

            // ── Aportes empleador (Ley 100/1993 + exoneración Ley 1607/2012)
            //
            // SALUD, SENA, ICBF son EXONERADOS cuando se cumplen DOS condiciones:
            //   1. El salario individual del trabajador es ≤ 10 SMMLV
            //   2. El empleador es persona jurídica (o natural con ≥ 2 trabajadores)
            //
            // En este sistema asumimos que TODO tenant es persona jurídica
            // (es un SaaS para empresas), por lo que la única condición es
            // el umbral salarial. La validación de tipo de empleador se
            // puede agregar al modelo Tenant en una futura iteración.
            $aplicaExoneracion1607 = $salario <= self::UMBRAL_PARAFISCAL * self::SMMLV_2026;

            $empPension = round($ibc * self::PENSION_EMPLEADOR, 4);
            $empArl     = round($ibc * ($altoRiesgo ? self::ARL_RIESGO_V : self::ARL_RIESGO_I), 4);
            $empCcf     = round($ibc * self::CAJA_COMPENSACION, 4);
            $empSalud   = $aplicaExoneracion1607 ? 0.0 : round($ibc * self::SALUD_EMPLEADOR, 4);
            $empSena    = $aplicaExoneracion1607 ? 0.0 : round($ibc * self::SENA, 4);
            $empIcbf    = $aplicaExoneracion1607 ? 0.0 : round($ibc * self::ICBF, 4);

            // ── Provisiones laborales (base devengado: salario + aux. transp.)
            // Estos conceptos son obligaciones laborales que la empresa debe
            // provisionar mensualmente y pagar en momentos definidos.
            $baseProvisiones = $salarioPeriodo + $auxTransporte;
            $cesantias       = round($baseProvisiones * self::CESANTIAS_FACTOR, 4);
            // Intereses sobre cesantías = 12% anual proporcional al periodo
            $intCesantias    = round($cesantias * self::INT_CESANTIAS_ANUAL * ($dias / 360), 4);
            $prima           = round($baseProvisiones * self::PRIMA_FACTOR, 4);
            // Vacaciones se calculan SOLO sobre salario (sin aux. transporte)
            $vacaciones      = round($salarioPeriodo * self::VACACIONES_FACTOR, 4);

            // ── Total devengado del mes (lo que se le adeuda al empleado
            //    como ingreso del periodo, base del neto a pagar).
            //    NO incluye provisiones: cesantías/prima/vacaciones son
            //    pasivos laborales del empleador acumulados; no afectan
            //    el neto líquido del mes.
            $totalDevengado = $salarioPeriodo + $auxTransporte + $totalHorasExtra
                + (float) ($extras['bonificacion'] ?? 0)
                + (float) ($extras['comision'] ?? 0);

            $totalDeduccion = $saludEmp + $pensionEmp
                + (float) ($extras['embargo'] ?? 0)
                + (float) ($extras['libranza'] ?? 0);

            $netoPagar = round($totalDevengado - $totalDeduccion, 4);

            // ── Crear liquidación ────────────────────────────────────────
            $liq = LiquidacionNomina::create([
                'periodo_nomina_id' => $periodo->id,
                'empleado_id'       => $empleado->id,
                'contrato_id'       => $contrato->id,
                'total_devengado'   => $totalDevengado,
                'total_deduccion'   => $totalDeduccion,
                'neto_pagar'        => $netoPagar,
                'dias_laborados'    => $dias,
                'estado'            => 'borrador',
            ]);

            // ── Crear líneas ─────────────────────────────────────────────
            $conceptos = ConceptoNomina::whereIn('codigo', [
                'BASICO', 'AUX_TRANSPORTE', 'H_EXTRA_DIURNA', 'H_EXTRA_NOCT',
                'BONIF', 'COMISION',
                'CESANTIAS', 'INT_CESANTIAS', 'PRIMA', 'VACACIONES',
                'DED_SALUD', 'DED_PENSION', 'DED_EMBARGO', 'DED_LIBRANZA',
                'EMP_SALUD', 'EMP_PENSION', 'EMP_ARL', 'EMP_CCF', 'EMP_SENA', 'EMP_ICBF',
            ])->get()->keyBy('codigo');

            $lineas = [];

            // Devengados
            $lineas[] = $this->linea($liq, $conceptos['BASICO'] ?? null, $dias, $salario / 30, $salarioPeriodo, 'devengado');

            // Auxilio de transporte (si aplica)
            if ($auxTransporte > 0) {
                $lineas[] = $this->linea(
                    $liq,
                    $conceptos['AUX_TRANSPORTE'] ?? null,
                    $dias,
                    self::AUX_TRANSPORTE_2026 / 30,
                    $auxTransporte,
                    'devengado',
                    'Auxilio de transporte (Ley 15/1959) — salario ≤ 2 SMMLV',
                );
            }

            if ($horasExtra > 0) {
                $lineas[] = $this->linea($liq, $conceptos['H_EXTRA_DIURNA'] ?? null, $horasExtra, round($valorHoraExtra * 1.25, 4), round($horasExtra * $valorHoraExtra * 1.25, 4), 'devengado');
            }
            if ($horasExtraNocturna > 0) {
                $lineas[] = $this->linea($liq, $conceptos['H_EXTRA_NOCT'] ?? null, $horasExtraNocturna, round($valorHoraExtra * 1.75, 4), round($horasExtraNocturna * $valorHoraExtra * 1.75, 4), 'devengado');
            }

            if (isset($extras['bonificacion']) && $extras['bonificacion'] > 0) {
                $lineas[] = $this->linea($liq, $conceptos['BONIF'] ?? null, 1, (float) $extras['bonificacion'], (float) $extras['bonificacion'], 'devengado');
            }

            // Provisiones laborales — pasivos a cargo del empleador que se
            // acumulan mensualmente (no son neto a pagar; van a 251xxx/252xxx
            // en el asiento contable de nómina, ver BUG-013).
            $lineas[] = $this->linea($liq, $conceptos['CESANTIAS'] ?? null, 1, $cesantias, $cesantias, 'devengado', 'Cesantías 8.33% sobre devengado');
            $lineas[] = $this->linea($liq, $conceptos['INT_CESANTIAS'] ?? null, 1, $intCesantias, $intCesantias, 'devengado', "Intereses cesantías 12% anual ({$dias}/360 días)");
            $lineas[] = $this->linea($liq, $conceptos['PRIMA'] ?? null, 1, $prima, $prima, 'devengado', 'Prima de servicios 8.33% sobre devengado');
            $lineas[] = $this->linea($liq, $conceptos['VACACIONES'] ?? null, 1, $vacaciones, $vacaciones, 'devengado', 'Vacaciones 4.17% sobre salario');

            // Deducciones
            $lineas[] = $this->linea($liq, $conceptos['DED_SALUD'] ?? null, 1, $saludEmp, $saludEmp, 'deduccion', 'Salud empleado 4%');
            $lineas[] = $this->linea($liq, $conceptos['DED_PENSION'] ?? null, 1, $pensionEmp, $pensionEmp, 'deduccion', 'Pensión empleado 4%');

            // Aportes empleador (Ley 100 + exoneración Ley 1607).
            // Solo persistimos las que tengan valor > 0 para mantener la
            // tabla limpia: si hay exoneración, EMP_SALUD/SENA/ICBF se omiten.
            $notaExoneracion = $aplicaExoneracion1607
                ? ' (EXONERADO Ley 1607 — salario ≤ 10 SMMLV)'
                : '';

            $lineas[] = $this->linea($liq, $conceptos['EMP_PENSION'] ?? null, 1, $empPension, $empPension, 'aporte_empleador', 'Pensión empleador 12%');
            $lineas[] = $this->linea($liq, $conceptos['EMP_ARL'] ?? null, 1, $empArl, $empArl, 'aporte_empleador', $altoRiesgo ? 'ARL alto riesgo 6.96%' : 'ARL riesgo I 0.522%');
            $lineas[] = $this->linea($liq, $conceptos['EMP_CCF'] ?? null, 1, $empCcf, $empCcf, 'aporte_empleador', 'Caja de Compensación 4%');

            if ($empSalud > 0) {
                $lineas[] = $this->linea($liq, $conceptos['EMP_SALUD'] ?? null, 1, $empSalud, $empSalud, 'aporte_empleador', 'Salud empleador 8.5%' . $notaExoneracion);
            }
            if ($empSena > 0) {
                $lineas[] = $this->linea($liq, $conceptos['EMP_SENA'] ?? null, 1, $empSena, $empSena, 'aporte_empleador', 'SENA 2%' . $notaExoneracion);
            }
            if ($empIcbf > 0) {
                $lineas[] = $this->linea($liq, $conceptos['EMP_ICBF'] ?? null, 1, $empIcbf, $empIcbf, 'aporte_empleador', 'ICBF 3%' . $notaExoneracion);
            }

            LiquidacionLinea::insert(array_filter($lineas));

            return $liq->load('lineas.concepto');
        });
    }

    /** @return array<string, mixed> */
    private function linea(
        LiquidacionNomina $liq,
        ?ConceptoNomina $concepto,
        float $cantidad,
        float $valorUnitario,
        float $valorTotal,
        string $tipo,
        string $nota = '',
    ): array {
        if ($concepto === null) {
            return [];
        }

        return [
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'liquidacion_id'  => $liq->id,
            'concepto_id'     => $concepto->id,
            'cantidad'        => $cantidad,
            'valor_unitario'  => $valorUnitario,
            'valor_total'     => $valorTotal,
            'tipo'            => $tipo,
            'nota'            => $nota,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }

    /**
     * Calcula prestaciones sociales (prima, cesantías, vacaciones) para un rango de fechas.
     * Útil para liquidaciones definitivas o provisiones mensuales.
     *
     * @return array{prima: float, cesantias: float, int_cesantias: float, vacaciones: float}
     */
    public function calcularPrestaciones(float $salario, int $dias): array
    {
        $prima        = round($salario * $dias / 360, 4);
        $cesantias    = round($salario * $dias / 360, 4);
        $intCesantias = round($cesantias * 0.12 * ($dias / 360), 4);
        $vacaciones   = round($salario * $dias / 730, 4);

        return compact('prima', 'cesantias', 'int_cesantias', 'vacaciones');
    }
}
