<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Sección de Balance General (Corriente / No Corriente) o sub-clasificación de P&G.
 *
 * Agrupa grupos PUC con su total. La separación corriente / no_corriente es exigida
 * por NIC 1 párr. 60.
 */
final readonly class SeccionBalanceDto
{
    /**
     * @param  list<GrupoBalanceDto>  $grupos
     */
    public function __construct(
        public string $nombre,                     // 'Activos Corrientes' | 'Activos No Corrientes' | ...
        public string $total,
        public ?string $totalAnterior,
        public array $grupos,
    ) {}
}
