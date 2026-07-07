<?php

declare(strict_types=1);

namespace App\Services\Impuesto;

use App\Models\Tenant\Impuesto;
use App\Repositories\Contracts\TarifaIcaRepositoryInterface;
use App\Repositories\Contracts\UvtAnualRepositoryInterface;
use App\Services\Impuesto\DTOs\ResultadoCalculoImpuestoDto;
use App\Support\Bc;
use RuntimeException;

/**
 * Calcula el monto de un impuesto dado una base, respetando:
 *   - Vigencias (solo aplica tarifas vigentes a la fecha dada)
 *   - Base mínima en UVT (Decreto 2418/2013 art. 6 y normas concordantes)
 *   - Régimen de ICA por municipio + CIIU (Ley 14/1983 y estatutos municipales)
 *
 * Regla NO negociable (Arquitecto §10):
 *   NUNCA hardcodear tarifas — siempre vía tabla `impuestos` o `tarifas_ica`.
 *
 * Ejemplo de uso:
 *   $resultado = $calculador->calcular(
 *       base: '5000000',
 *       codigoImpuesto: 'RF-01',    // Honorarios 10%
 *       fecha: new DateTimeImmutable('2026-01-15'),
 *   );
 *
 *   $resultado->impuestoCalculado;  // '500000.0000'
 *   $resultado->baseBajoUmbral;     // false
 */
final class ImpuestoCalculadorService
{
    public function __construct(
        private readonly UvtAnualRepositoryInterface $uvtRepo,
        private readonly TarifaIcaRepositoryInterface $icaRepo,
    ) {}

    /**
     * Calcula el impuesto sobre `base` usando el impuesto identificado por `codigo`.
     *
     * @param  string                  $base             Monto gravable en COP (DECIMAL string)
     * @param  string                  $codigoImpuesto   Código del catálogo de impuestos del tenant
     * @param  \DateTimeInterface|null $fecha            Fecha de vigencia (default hoy)
     * @param  string|null             $municipioDane    Código DANE para ICA (obligatorio si tipo=reteica)
     * @param  string|null             $actividadCiiu    Código CIIU de la actividad (para ICA)
     *
     * @throws RuntimeException  si el impuesto no existe o no está vigente
     */
    public function calcular(
        string $base,
        string $codigoImpuesto,
        ?\DateTimeInterface $fecha = null,
        ?string $municipioDane = null,
        ?string $actividadCiiu = null,
    ): ResultadoCalculoImpuestoDto {
        $fecha ??= new \DateTimeImmutable();
        $base   = Bc::n($base);

        $impuesto = $this->cargarVigente($codigoImpuesto, $fecha);

        // ── Tarifa ─────────────────────────────────────────────────────────────
        $tarifa = $this->resolverTarifa($impuesto, $municipioDane, $actividadCiiu, $fecha);

        // ── Validación base mínima (UVT) ───────────────────────────────────────
        [$baseMinimaUvt, $baseMinimaAplicadaCop] = $this->calcularBaseMinima($impuesto, $fecha);

        $baseBajoUmbral = false;
        if ($baseMinimaAplicadaCop !== null && Bc::cmp($base, $baseMinimaAplicadaCop) < 0) {
            $baseBajoUmbral = true;
        }

        // ── Cálculo ────────────────────────────────────────────────────────────
        $impuestoCalculado = $baseBajoUmbral
            ? '0.0000'
            : Bc::porcentaje($base, $tarifa);

        return new ResultadoCalculoImpuestoDto(
            codigo:                  $impuesto->codigo,
            nombre:                  $impuesto->nombre,
            tipo:                    $impuesto->tipo,
            base:                    $base,
            tarifaPorcentaje:        $tarifa,
            impuestoCalculado:       $impuestoCalculado,
            baseMinimaUvt:           $baseMinimaUvt,
            baseMinimaAplicadaCop:   $baseMinimaAplicadaCop,
            baseBajoUmbral:          $baseBajoUmbral,
            cuentaContableId:        $impuesto->cuenta_contable_id,
            cuentaContrapartidaId:   $impuesto->cuenta_contrapartida_id,
        );
    }

    /**
     * Calcula múltiples impuestos sobre la misma base, acumulando el total.
     *
     * @param  list<string>  $codigos
     *
     * @return array{
     *     total: string,
     *     items: list<ResultadoCalculoImpuestoDto>,
     * }
     */
    public function calcularMultiples(
        string $base,
        array $codigos,
        ?\DateTimeInterface $fecha = null,
        ?string $municipioDane = null,
        ?string $actividadCiiu = null,
    ): array {
        $total = '0';
        $items = [];

        foreach ($codigos as $codigo) {
            $resultado = $this->calcular($base, $codigo, $fecha, $municipioDane, $actividadCiiu);
            $items[]   = $resultado;
            $total     = Bc::add($total, $resultado->impuestoCalculado);
        }

        return ['total' => $total, 'items' => $items];
    }

    // ── Helpers privados ────────────────────────────────────────────────────────

    private function cargarVigente(string $codigo, \DateTimeInterface $fecha): Impuesto
    {
        /** @var Impuesto|null $impuesto */
        $impuesto = Impuesto::query()
            ->vigentes($fecha)
            ->where('codigo', $codigo)
            ->first();

        if ($impuesto === null) {
            throw new RuntimeException(
                "Impuesto '{$codigo}' no encontrado o no vigente para la fecha {$fecha->format('Y-m-d')}."
            );
        }

        return $impuesto;
    }

    /**
     * Resuelve la tarifa efectiva a aplicar.
     *
     * Para ICA con municipio + CIIU: consulta `tarifas_ica` (estatuto municipal).
     * Para el resto: usa `impuestos.tarifa_porcentaje` directamente.
     */
    private function resolverTarifa(
        Impuesto $impuesto,
        ?string $municipioDane,
        ?string $actividadCiiu,
        \DateTimeInterface $fecha,
    ): string {
        if ($impuesto->tipo === Impuesto::TIPO_RETEICA && $municipioDane !== null) {
            $ciiu = $actividadCiiu ?? $impuesto->actividad_ciiu;

            if ($ciiu !== null) {
                $tarifaIca = $this->icaRepo->vigenteParaMunicipioYCiiu($municipioDane, $ciiu, $fecha);

                if ($tarifaIca !== null) {
                    // ICA se almacena en por-mil (‰) — convertir a porcentaje para Bc::porcentaje
                    // Ejemplo: 9.66‰ = 0.966% → dividir entre 10
                    $porMil = Bc::n((string) $tarifaIca->tarifa_por_mil);
                    return Bc::div($porMil, '10', 4);
                }
                // Fallback: tarifa genérica del catálogo si no hay tarifa municipal específica
            }
        }

        return Bc::n((string) $impuesto->tarifa_porcentaje);
    }

    /**
     * Calcula la base mínima en COP si el impuesto tiene `base_minima_uvt`.
     *
     * @return array{0: ?string, 1: ?string}  [baseMinimaUvt, baseMinimaAplicadaCop]
     */
    private function calcularBaseMinima(Impuesto $impuesto, \DateTimeInterface $fecha): array
    {
        if ($impuesto->base_minima_uvt === null) {
            return [null, null];
        }

        $uvtStr = Bc::n((string) $impuesto->base_minima_uvt);

        if (Bc::cmp($uvtStr, '0') <= 0) {
            return [null, null];
        }

        $baseMinimaAplicadaCop = $this->uvtRepo->uvtACop($uvtStr, $fecha);

        return [$uvtStr, $baseMinimaAplicadaCop];
    }
}
