<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Fila del Balance de Comprobación (una cuenta hoja, 12 columnas).
 *
 * Columnas según estructura estándar PUC Colombia:
 *   Saldo Inicial (D/C) | Movimientos (D/C) | Saldo Final (D/C)
 *   Ajustes (D/C) | Saldo Ajustado (D/C)  ← ajustes = comprobantes tipo AJ o CN
 *
 * Todos los valores son DECIMAL(18,4) como string.
 */
final readonly class FilaBalanceComprobacionDto
{
    public function __construct(
        public string $codigo,
        public string $nombre,
        public int $clase,
        public string $naturaleza,             // 'debito' | 'credito'

        // Saldo Inicial
        public string $saldoInicialDebito,
        public string $saldoInicialCredito,

        // Movimientos del periodo (solo comprobantes ordinarios, NO ajustes)
        public string $movimientoDebito,
        public string $movimientoCredito,

        // Saldo Final = SI + Mov
        public string $saldoFinalDebito,
        public string $saldoFinalCredito,

        // Ajustes del periodo (comprobantes tipo AJ / CN / AP)
        public string $ajusteDebito,
        public string $ajusteCredito,

        // Saldo Ajustado = Saldo Final + Ajustes
        public string $saldoAjustadoDebito,
        public string $saldoAjustadoCredito,
    ) {}
}
