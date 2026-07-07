<?php

declare(strict_types=1);

namespace App\Repositories\Central;

use App\Models\Central\TarifaIca;
use App\Repositories\Contracts\TarifaIcaRepositoryInterface;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * Tarifa ICA por municipio × CIIU vigente.
 *
 * Caché agresivo (24h TTL): las tarifas municipales se modifican típicamente
 * 1 vez al año por Acuerdo. La invalidación al actualizar una tarifa la dispara
 * el endpoint admin central (no implementado en MVP — actualización manual).
 */
final class TarifaIcaRepository implements TarifaIcaRepositoryInterface
{
    private const CACHE_PREFIX = 'tarifa_ica:';
    private const CACHE_TTL    = 86400; // 24 horas

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function vigenteParaMunicipioYCiiu(
        string $municipioDane,
        string $codigoCiiu,
        ?\DateTimeInterface $fecha = null,
    ): ?TarifaIca {
        $fecha   ??= new \DateTimeImmutable();
        $fechaIso = $fecha->format('Y-m-d');
        $key      = self::CACHE_PREFIX . "{$municipioDane}:{$codigoCiiu}:{$fechaIso}";

        $cached = $this->cache->get($key);
        if ($cached instanceof TarifaIca) {
            return $cached;
        }
        if ($cached === false) {
            return null; // hit negativo cacheado
        }

        $tarifa = TarifaIca::query()
            ->vigentes($fecha)
            ->delMunicipio($municipioDane)
            ->where('codigo_actividad_ciiu', $codigoCiiu)
            ->orderByDesc('vigencia_desde')
            ->first();

        // Cachear hit positivo y negativo (false) para ahorrar queries
        $this->cache->put($key, $tarifa ?? false, self::CACHE_TTL);

        return $tarifa;
    }

    public function vigentesDelMunicipio(
        string $municipioDane,
        ?\DateTimeInterface $fecha = null,
    ): Collection {
        $fecha ??= new \DateTimeImmutable();

        return TarifaIca::query()
            ->vigentes($fecha)
            ->delMunicipio($municipioDane)
            ->orderBy('codigo_actividad_ciiu')
            ->get();
    }
}
