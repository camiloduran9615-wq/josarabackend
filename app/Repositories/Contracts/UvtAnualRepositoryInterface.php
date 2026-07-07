<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Central\UvtAnual;

/**
 * Acceso a la UVT vigente o histórica.
 *
 * Toda lógica tributaria que calcule bases mínimas en UVT debe usar este contrato.
 * Nunca hardcodear el valor de la UVT.
 */
interface UvtAnualRepositoryInterface
{
    /**
     * UVT vigente a la fecha dada (default hoy).
     *
     * @throws \RuntimeException  si no hay UVT vigente cargada (configuración inválida)
     */
    public function vigente(?\DateTimeInterface $fecha = null): UvtAnual;

    /**
     * UVT del año fiscal dado.
     *
     * @throws \RuntimeException  si no existe UVT para el año
     */
    public function deAnio(int $anio): UvtAnual;

    /**
     * Convierte un número de UVT a su valor en COP usando la UVT vigente a la fecha.
     */
    public function uvtACop(string|float|int $uvt, ?\DateTimeInterface $fecha = null): string;
}
