<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Validación de la ecuación contable: Activo = Pasivo + Patrimonio.
 *
 * `balanceado` es `true` si |delta| <= 0.01 COP (tolerancia partida doble).
 */
final readonly class EcuacionBalanceDto
{
    public function __construct(
        public string $activo,
        public string $pasivoMasPatrimonio,
        public string $diferencia,
        public bool $balanceado,
    ) {}
}
