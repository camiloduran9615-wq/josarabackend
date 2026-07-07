<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Central\TarifaIca;
use Illuminate\Support\Collection;

/**
 * Acceso al catálogo central de tarifas ICA por municipio + CIIU + vigencia.
 */
interface TarifaIcaRepositoryInterface
{
    /**
     * Tarifa vigente para un municipio (código DANE) y una actividad CIIU.
     * Devuelve `null` si no hay tarifa configurada — el caller decide
     * si lanza excepción o aplica fallback.
     */
    public function vigenteParaMunicipioYCiiu(
        string $municipioDane,
        string $codigoCiiu,
        ?\DateTimeInterface $fecha = null,
    ): ?TarifaIca;

    /**
     * Todas las tarifas vigentes de un municipio (para cargar selectores en UI).
     *
     * @return Collection<int, TarifaIca>
     */
    public function vigentesDelMunicipio(
        string $municipioDane,
        ?\DateTimeInterface $fecha = null,
    ): Collection;
}
