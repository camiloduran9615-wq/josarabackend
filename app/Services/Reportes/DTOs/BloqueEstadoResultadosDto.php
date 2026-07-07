<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Bloque de Estado de Resultados (Ingresos, Costo Ventas, Gastos Operacionales, etc.).
 *
 * Cada bloque tiene líneas de detalle (cuentas hoja) y su subtotal.
 * `esSubtotal` indica que esta fila es calculada, no una cuenta real.
 */
final readonly class BloqueEstadoResultadosDto
{
    /**
     * @param  list<LineaEstadoResultadosDto>  $lineas
     */
    public function __construct(
        public string $codigo,
        public string $nombre,
        public string $total,
        public ?string $totalComparativo,
        public array $lineas,
    ) {}
}
