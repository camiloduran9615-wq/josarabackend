<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Línea de detalle en Estado de Resultados: una cuenta hoja con su saldo del periodo.
 *
 * `saldo` siempre positivo para presentación (la estructura padre define si suma o resta).
 */
final readonly class LineaEstadoResultadosDto
{
    public function __construct(
        public string $codigo,
        public string $nombre,
        public string $saldo,
        public ?string $saldoComparativo = null,
    ) {}
}
