<?php

declare(strict_types=1);

namespace App\Services\Periodo;

use App\Events\CierreAnual\CierreAnualEjecutado;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use App\Services\Asiento\ConsecutivoAsientoService;
use App\Support\Bc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ejecuta el cierre contable anual para un año fiscal.
 *
 * Genera DOS asientos de cierre automáticos (NIC 1, Decreto 2649/1993 art. 52):
 *
 * ASIENTO 1 — Cancelación de cuentas de resultado (clases 4, 5, 6, 7):
 *   Ingresos (cl.4, naturaleza crédito) → se DEBITAN por su saldo neto
 *   Costos/Gastos (cl.5/6/7, naturaleza débito) → se ACREDITAN por su saldo neto
 *   Contrapartida balanceadora en 5905 (Ganancias y Pérdidas):
 *     - Utilidad → Crédito en 5905
 *     - Pérdida  → Débito en 5905
 *
 * ASIENTO 2 — Traslado resultado a Patrimonio:
 *   Si Utilidad: Débito 5905 / Crédito 3606 (Utilidad del ejercicio)
 *   Si Pérdida:  Crédito 5905 / Débito 3606
 *
 * Post-cierre: 5905 queda en CERO — verificación obligatoria.
 *
 * Idempotencia: si el año ya fue cerrado (asientos CI existen para el período
 * anual), lanza `PeriodoOperacionInvalidaException` sin crear duplicados.
 *
 * Nota sobre nomenclatura PUC:
 *   3606 = "Utilidad del ejercicio" (si utilidad, saldo crédito)
 *   3607 = "Pérdida del ejercicio" (si pérdida, saldo débito)
 *   En MVP usamos cuenta 3606 para ambos sentidos (convenio simplificado).
 *   El PucSeeder debe garantizar que ambas cuentas existan.
 */
final class CierreAnualService
{
    private const TIPO_COMPROBANTE_CIERRE = 'CI';
    private const CODIGO_5905_GRUPO = '5905'; // prefijo para búsqueda
    private const CODIGO_3606_GRUPO = '3605'; // prefijo cuenta patrimonio resultado (Utilidad/Pérdida del Ejercicio)

    public function __construct(
        private readonly ConsecutivoAsientoService $consecutivos,
    ) {}

    /**
     * Ejecuta el cierre anual completo para el `año` dado.
     *
     * @return array{
     *     anio: int,
     *     resultado: string,
     *     monto: string,
     *     asiento_cancelacion_id: string,
     *     asiento_traslado_id: string,
     * }
     *
     * @throws PeriodoOperacionInvalidaException  si el periodo anual no existe, no está cerrado,
     *                                            o si el año ya fue procesado
     * @throws RuntimeException                   si faltan cuentas 5905 o 3606
     */
    public function ejecutar(int $anio, User $contador): array
    {
        $periodoAnual = $this->cargarPeriodoAnual($anio);

        if ($periodoAnual->estaBloqueadoFiscalmente()) {
            throw new PeriodoOperacionInvalidaException(
                "El año fiscal {$anio} ya está bloqueado fiscalmente — cierre no permitido."
            );
        }

        if (! $periodoAnual->estaCerrado()) {
            throw new PeriodoOperacionInvalidaException(
                "El periodo anual {$anio} debe estar cerrado antes del cierre fiscal. "
                ."Estado actual: {$periodoAnual->estado}."
            );
        }

        $this->verificarNoReprocesado($periodoAnual);

        $cuenta5905 = $this->cargarCuentaPorPrefijo(self::CODIGO_5905_GRUPO);
        $cuenta3606 = $this->cargarCuentaPorPrefijo(self::CODIGO_3606_GRUPO);

        return DB::transaction(function () use ($anio, $periodoAnual, $contador, $cuenta5905, $cuenta3606): array {
            // 1. Calcular saldos netos de todas las cuentas de resultado
            $saldosResultado = $this->cargarSaldosResultado($anio);

            // 2. Calcular el resultado neto (Ingresos - Costos - Gastos)
            $resultadoNeto = $this->calcularResultadoNeto($saldosResultado);

            // 3. Asiento 1: Cancelación de cuentas de resultado → 5905
            $asientoCancelacion = $this->crearAsientoCancelacion(
                $periodoAnual, $contador, $saldosResultado, $cuenta5905, $resultadoNeto,
            );

            // 4. Asiento 2: Traslado 5905 → 3606
            $asientoTraslado = $this->crearAsientoTraslado(
                $periodoAnual, $contador, $cuenta5905, $cuenta3606, $resultadoNeto,
            );

            // 5. Bloquear el periodo anual fiscalmente (no permite reapertura ordinaria)
            $periodoAnual->update([
                'estado'                    => PeriodoContable::ESTADO_BLOQUEADO_FISCAL,
                'bloqueado_fiscal_por_id'   => $contador->id,
                'bloqueado_fiscal_at'       => now(),
            ]);

            // 6. Resultado del ejercicio
            $tipoResultado = Bc::cmp(Bc::abs($resultadoNeto), '0.01') > 0
                ? (Bc::cmp($resultadoNeto, '0') > 0 ? 'utilidad' : 'perdida')
                : 'equilibrio';

            event(new CierreAnualEjecutado(
                anio:            $anio,
                asientos:        [$asientoCancelacion, $asientoTraslado],
                ejecutadoPor:    $contador,
                resultado:       $tipoResultado,
                montoResultado:  Bc::abs($resultadoNeto),
            ));

            return [
                'anio'                    => $anio,
                'resultado'               => $tipoResultado,
                'monto'                   => Bc::abs($resultadoNeto),
                'asiento_cancelacion_id'  => $asientoCancelacion->id,
                'asiento_traslado_id'     => $asientoTraslado->id,
            ];
        });
    }

    // ── Helpers privados ────────────────────────────────────────────────────────

    private function cargarPeriodoAnual(int $anio): PeriodoContable
    {
        $codigoAnual = "FY-{$anio}";

        /** @var PeriodoContable|null $periodo */
        $periodo = PeriodoContable::query()
            ->where('tipo', PeriodoContable::TIPO_ANUAL)
            ->where('codigo', $codigoAnual)
            ->first();

        if ($periodo === null) {
            // Intento alternativo: periodo anual por año fiscal sin el prefijo FY-
            /** @var PeriodoContable|null $periodo */
            $periodo = PeriodoContable::query()
                ->where('tipo', PeriodoContable::TIPO_ANUAL)
                ->where('año_fiscal', $anio)
                ->first();
        }

        if ($periodo === null) {
            throw new PeriodoOperacionInvalidaException(
                "No existe un periodo de tipo 'anual' para el año fiscal {$anio}."
            );
        }

        return $periodo;
    }

    private function verificarNoReprocesado(PeriodoContable $periodo): void
    {
        $existe = Asiento::query()
            ->where('periodo_id', $periodo->id)
            ->where('tipo_comprobante', self::TIPO_COMPROBANTE_CIERRE)
            ->where('estado', Asiento::ESTADO_APROBADO)
            ->exists();

        if ($existe) {
            throw new PeriodoOperacionInvalidaException(
                "El año fiscal {$periodo->año_fiscal} ya tiene asientos de cierre (CI). "
                .'Ejecutar dos veces generaría duplicados.'
            );
        }
    }

    private function cargarCuentaPorPrefijo(string $prefijo): CuentaContable
    {
        /** @var CuentaContable|null $cuenta */
        $cuenta = CuentaContable::query()
            ->where('codigo', 'LIKE', $prefijo . '%')
            ->where('acepta_movimientos', true)
            ->where('activo', true)
            ->orderBy('codigo')
            ->first();

        if ($cuenta === null) {
            throw new RuntimeException(
                "No se encontró cuenta contable con prefijo '{$prefijo}'. "
                .'Ejecutar PucSeeder primero.'
            );
        }

        return $cuenta;
    }

    /**
     * Carga los saldos netos (débito - crédito) de cuentas de resultado del año.
     *
     * @return list<array{
     *     cuenta_id: string, codigo: string, clase: int, naturaleza: string,
     *     saldo_debito: string, saldo_credito: string, saldo_neto: string,
     * }>
     */
    private function cargarSaldosResultado(int $anio): array
    {
        $sql = <<<'SQL'
            WITH totales AS (
                SELECT
                    cs.cuenta_contable_id,
                    SUM(cs.saldo_inicial_debito  + cs.movimiento_debito)  AS total_d,
                    SUM(cs.saldo_inicial_credito + cs.movimiento_credito) AS total_c
                FROM cuenta_saldos cs
                INNER JOIN periodos_contables p ON p.id = cs.periodo_id
                WHERE p.año_fiscal = ?
                GROUP BY cs.cuenta_contable_id
            )
            SELECT
                cc.id            AS cuenta_id,
                cc.codigo        AS codigo,
                cc.clase         AS clase,
                cc.naturaleza    AS naturaleza,
                COALESCE(t.total_d, 0)::text AS saldo_debito,
                COALESCE(t.total_c, 0)::text AS saldo_credito
            FROM cuentas_contables cc
            INNER JOIN totales t ON t.cuenta_contable_id = cc.id
            WHERE cc.clase IN (4, 5, 6, 7)
              AND cc.acepta_movimientos = true
              AND cc.activo = true
            ORDER BY cc.codigo
        SQL;

        $rows = DB::select($sql, [$anio]);

        $resultado = [];
        foreach ($rows as $r) {
            $d = Bc::n((string) $r->saldo_debito);
            $c = Bc::n((string) $r->saldo_credito);

            // Saldo neto: desde la perspectiva de la cuenta
            // Ingresos (naturaleza crédito) → neto = C - D
            // Costos/Gastos (naturaleza débito) → neto = D - C
            $neto = (string) $r->naturaleza === 'credito'
                ? Bc::sub($c, $d)
                : Bc::sub($d, $c);

            // Solo incluir cuentas con movimiento real
            if (Bc::cmp(Bc::abs($neto), '0.01') <= 0) {
                continue;
            }

            $resultado[] = [
                'cuenta_id'    => (string) $r->cuenta_id,
                'codigo'       => (string) $r->codigo,
                'clase'        => (int) $r->clase,
                'naturaleza'   => (string) $r->naturaleza,
                'saldo_debito' => $d,
                'saldo_credito'=> $c,
                'saldo_neto'   => $neto,
            ];
        }

        return $resultado;
    }

    /**
     * Resultado neto del ejercicio (positivo = utilidad, negativo = pérdida).
     * Formula: Σ Ingresos(saldo neto) - Σ Costos(saldo neto) - Σ Gastos(saldo neto)
     *
     * @param  list<array<string, mixed>>  $saldos
     */
    private function calcularResultadoNeto(array $saldos): string
    {
        $neto = '0';
        foreach ($saldos as $s) {
            $clase = (int) ($s['clase'] ?? 0);
            $saldoNeto = (string) ($s['saldo_neto'] ?? '0');

            if ($clase === 4) {
                // Ingresos suman al resultado
                $neto = Bc::add($neto, $saldoNeto);
            } else {
                // Costos y gastos restan al resultado
                $neto = Bc::sub($neto, $saldoNeto);
            }
        }

        return $neto;
    }

    /**
     * Asiento 1: cancela todas las cuentas de resultado dejándolas en cero.
     * La partida balanceadora va a 5905.
     *
     * @param  list<array<string, mixed>>  $saldosResultado
     */
    private function crearAsientoCancelacion(
        PeriodoContable $periodo,
        User $contador,
        array $saldosResultado,
        CuentaContable $cuenta5905,
        string $resultadoNeto,
    ): Asiento {
        $fechaCierre = CarbonImmutable::parse("{$periodo->año_fiscal}-12-31");

        /** @var Asiento $asiento */
        $asiento = (new Asiento())->forceFill([
            'fecha'              => $fechaCierre->toDateString(),
            'periodo_id'         => $periodo->id,
            'tipo_comprobante'   => self::TIPO_COMPROBANTE_CIERRE,
            'estado'             => Asiento::ESTADO_APROBADO,
            'tipo_movimiento'    => Asiento::TIPO_NORMAL,
            'descripcion'        => "Asiento de cierre anual {$periodo->año_fiscal} — Cancelación de cuentas de resultado",
            'comprobante'        => 'Cierre Anual',
            'numero_documento'   => (string) $periodo->año_fiscal,
            'created_by_id'      => $contador->id,
            'approved_by_id'     => $contador->id,
            'approved_at'        => now(),
        ]);
        $asiento->save();

        // Líneas por cuenta de resultado
        foreach ($saldosResultado as $s) {
            $clase = (int) ($s['clase'] ?? 0);
            $saldoNeto = Bc::abs((string) ($s['saldo_neto'] ?? '0'));
            $naturaleza = (string) ($s['naturaleza'] ?? 'debito');
            $cuentaId = (string) ($s['cuenta_id'] ?? '');

            // Para cancelar la cuenta, aplicamos la operación inversa a su naturaleza:
            // Ingreso (crédito) → se DEBITA
            // Costo/Gasto (débito) → se ACREDITA
            if ($clase === 4 || $naturaleza === 'credito') {
                AsientoLinea::query()->create([
                    'asiento_id'       => $asiento->id,
                    'cuenta_id'        => $cuentaId,
                    'debito'           => $saldoNeto,
                    'credito'          => '0',
                    'descripcion_item' => 'Cancelación cierre anual',
                ]);
            } else {
                AsientoLinea::query()->create([
                    'asiento_id'       => $asiento->id,
                    'cuenta_id'        => $cuentaId,
                    'debito'           => '0',
                    'credito'          => $saldoNeto,
                    'descripcion_item' => 'Cancelación cierre anual',
                ]);
            }
        }

        // Línea balanceadora en 5905
        $neto = Bc::abs($resultadoNeto);
        if (Bc::cmp($neto, '0.01') > 0) {
            $utilidad = Bc::cmp($resultadoNeto, '0') > 0;
            AsientoLinea::query()->create([
                'asiento_id'       => $asiento->id,
                'cuenta_id'        => $cuenta5905->id,
                'debito'           => $utilidad ? '0' : $neto,
                'credito'          => $utilidad ? $neto : '0',
                'descripcion_item' => $utilidad
                    ? 'Utilidad del ejercicio — cierre anual'
                    : 'Pérdida del ejercicio — cierre anual',
            ]);
        }

        $this->consecutivos->asignar($asiento);

        return $asiento;
    }

    /**
     * Asiento 2: traslada el saldo de 5905 a la cuenta de patrimonio 3606.
     */
    private function crearAsientoTraslado(
        PeriodoContable $periodo,
        User $contador,
        CuentaContable $cuenta5905,
        CuentaContable $cuenta3606,
        string $resultadoNeto,
    ): Asiento {
        $neto = Bc::abs($resultadoNeto);
        $utilidad = Bc::cmp($resultadoNeto, '0') > 0;
        $fechaCierre = CarbonImmutable::parse("{$periodo->año_fiscal}-12-31");

        /** @var Asiento $asiento */
        $asiento = (new Asiento())->forceFill([
            'fecha'              => $fechaCierre->toDateString(),
            'periodo_id'         => $periodo->id,
            'tipo_comprobante'   => self::TIPO_COMPROBANTE_CIERRE,
            'estado'             => Asiento::ESTADO_APROBADO,
            'tipo_movimiento'    => Asiento::TIPO_NORMAL,
            'descripcion'        => "Asiento de cierre anual {$periodo->año_fiscal} — Traslado resultado a patrimonio",
            'comprobante'        => 'Cierre Anual',
            'numero_documento'   => (string) $periodo->año_fiscal,
            'created_by_id'      => $contador->id,
            'approved_by_id'     => $contador->id,
            'approved_at'        => now(),
        ]);
        $asiento->save();

        // Traslado: 5905 → 3606
        // Utilidad: Débito 5905 (cancela saldo crédito) / Crédito 3606
        // Pérdida:  Crédito 5905 (cancela saldo débito) / Débito 3606
        // Equilibrio (neto < 0.01): no se crean líneas — el asiento queda como traza de auditoría
        if (Bc::cmp($neto, '0.01') > 0) {
            AsientoLinea::query()->create([
                'asiento_id'       => $asiento->id,
                'cuenta_id'        => $cuenta5905->id,
                'debito'           => $utilidad ? $neto : '0',
                'credito'          => $utilidad ? '0' : $neto,
                'descripcion_item' => 'Traslado resultado a patrimonio',
            ]);

            AsientoLinea::query()->create([
                'asiento_id'       => $asiento->id,
                'cuenta_id'        => $cuenta3606->id,
                'debito'           => $utilidad ? '0' : $neto,
                'credito'          => $utilidad ? $neto : '0',
                'descripcion_item' => $utilidad
                    ? 'Utilidad neta del ejercicio'
                    : 'Pérdida neta del ejercicio',
            ]);
        }

        $this->consecutivos->asignar($asiento);

        return $asiento;
    }
}
