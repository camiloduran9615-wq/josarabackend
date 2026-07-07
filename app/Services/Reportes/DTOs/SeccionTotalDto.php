<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Sección de Balance General — Activo, Pasivo o Patrimonio — con sub-secciones.
 *
 * Activo y Pasivo se sub-dividen en Corriente / No Corriente (NIC 1 párr. 60).
 * Patrimonio en MVP queda en un solo bloque sin sub-secciones (puede expandirse
 * a "Capital Aportado" + "Reservas" + "Resultados" en una v1.1 si se solicita).
 */
final readonly class SeccionTotalDto
{
    /**
     * @param  list<SeccionBalanceDto>  $subsecciones
     */
    public function __construct(
        public string $total,
        public ?string $totalAnterior,
        public array $subsecciones,
    ) {}
}
