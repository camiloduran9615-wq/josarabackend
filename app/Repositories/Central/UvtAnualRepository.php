<?php

declare(strict_types=1);

namespace App\Repositories\Central;

use App\Models\Central\UvtAnual;
use App\Repositories\Contracts\UvtAnualRepositoryInterface;
use App\Support\Bc;
use Illuminate\Cache\Repository as CacheRepository;
use RuntimeException;

/**
 * Implementación del UvtAnualRepository con caché en memoria.
 *
 * La UVT cambia 1 vez al año — cacheamos agresivamente (24h TTL) para evitar
 * un round-trip a BD central en cada cálculo tributario.
 */
final class UvtAnualRepository implements UvtAnualRepositoryInterface
{
    private const CACHE_PREFIX = 'uvt:';
    private const CACHE_TTL    = 86400; // 24 horas

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function vigente(?\DateTimeInterface $fecha = null): UvtAnual
    {
        $fecha ??= new \DateTimeImmutable();
        $anio   = (int) $fecha->format('Y');

        return $this->deAnio($anio);
    }

    public function deAnio(int $anio): UvtAnual
    {
        $key = self::CACHE_PREFIX . $anio;

        /** @var UvtAnual|null $cached */
        $cached = $this->cache->get($key);

        if ($cached instanceof UvtAnual) {
            return $cached;
        }

        $uvt = UvtAnual::query()->find($anio);

        if ($uvt === null) {
            throw new RuntimeException(
                "No hay UVT configurada para el año {$anio}. Cargar via UvtAnualSeeder o admin central."
            );
        }

        $this->cache->put($key, $uvt, self::CACHE_TTL);

        return $uvt;
    }

    public function uvtACop(string|float|int $uvt, ?\DateTimeInterface $fecha = null): string
    {
        $valorUnidad = (string) $this->vigente($fecha)->valor_cop;

        return Bc::mul($uvt, $valorUnidad, 2);
    }
}
