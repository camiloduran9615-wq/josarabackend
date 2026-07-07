<?php

declare(strict_types=1);

namespace App\Services\Impuesto\DTOs;

/**
 * Resultado de un cálculo tributario individual.
 *
 * `baseBajoUmbral = true` cuando la base no alcanza la base mínima en UVT
 * y el impuesto resulta en cero por normativa (no por ausencia de tarifa).
 *
 * Todos los montos son DECIMAL(18,4) como string.
 */
final readonly class ResultadoCalculoImpuestoDto
{
    public function __construct(
        public string $codigo,
        public string $nombre,
        public string $tipo,
        public string $base,
        public string $tarifaPorcentaje,        // p.ej. '3.5000' para 3.5%
        public string $impuestoCalculado,        // = base × tarifa / 100 (o 0 si bajo umbral)
        public ?string $baseMinimaUvt,           // número de UVT requerido (null si no aplica)
        public ?string $baseMinimaAplicadaCop,   // baseMinimaUvt × valor UVT en COP
        public bool $baseBajoUmbral,             // true → impuesto = 0 por base insuficiente
        public string $cuentaContableId,
        public ?string $cuentaContrapartidaId,
    ) {}
}
