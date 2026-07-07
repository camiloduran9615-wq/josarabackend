<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Las 4 igualdades de validación del Balance de Comprobación.
 *
 * Cada par (D = C) es exigido por la partida doble. Si alguno falla,
 * hay inconsistencia en los asientos del periodo.
 *
 * `valido` = true solo si LAS 4 igualdades se cumplen (|delta| <= 0.01 COP).
 */
final readonly class ValidacionBalanceComprobacionDto
{
    public function __construct(
        // Σ SI Débito = Σ SI Crédito
        public string $totalSiDebito,
        public string $totalSiCredito,
        public string $deltaSi,
        public bool $siBalanceado,

        // Σ Mov Débito = Σ Mov Crédito
        public string $totalMovDebito,
        public string $totalMovCredito,
        public string $deltaMov,
        public bool $movBalanceado,

        // Σ Ajuste Débito = Σ Ajuste Crédito
        public string $totalAjDebito,
        public string $totalAjCredito,
        public string $deltaAj,
        public bool $ajBalanceado,

        // Σ Saldo Ajustado Débito = Σ Saldo Ajustado Crédito
        public string $totalSaDebito,
        public string $totalSaCredito,
        public string $deltaSa,
        public bool $saBalanceado,

        public bool $valido,
    ) {}
}
