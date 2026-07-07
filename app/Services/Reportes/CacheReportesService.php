<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wrapper de caché para reportes financieros con **singleflight pattern**.
 *
 * Problema: Balance General y Estado de Resultados son queries pesadas (decenas de ms
 * con miles de cuentas + comparativo). Si N usuarios piden el mismo reporte al mismo
 * tiempo justo cuando el cache acaba de invalidarse (post-AsientoAprobado), las N
 * requests calculan SIMULTÁNEAMENTE — stampede que tumba la BD.
 *
 * Solución: el primero que pide adquiere un lock corto (con `Cache::lock`); los demás
 * esperan brevemente el resultado del primero. Si el lock no se libera en `waitSeconds`,
 * el siguiente intenta computar él mismo (degradación elegante, no bloqueo infinito).
 *
 * Cache keys: convenidas con `InvalidarCacheReportesListener` (D3) para invalidación
 * coherente:
 *   - `tenant:{tid}:bg:{fecha_corte}:{hash}`        Balance General
 *   - `tenant:{tid}:er:{desde}:{hasta}:{hash}`      Estado de Resultados
 *   - `tenant:{tid}:lm:{cuenta_id}:{hash}`          Libro Mayor por cuenta
 *   - `tenant:{tid}:bc:{periodo_codigo}:{hash}`     Balance de Comprobación
 */
final class CacheReportesService
{
    /** TTL default para reportes — 1 hora. */
    public const DEFAULT_TTL = 3600;

    /** Tiempo máximo que un caller espera a que otro termine el cálculo. */
    private const LOCK_WAIT_SECONDS = 8;

    /** Tiempo máximo que el lock se mantiene en Redis (cobertura del cálculo). */
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Obtiene un reporte del cache. Si no existe, lo calcula con singleflight.
     *
     * @param  Closure(): array<string, mixed>  $compute
     *
     * @return array<string, mixed>
     */
    public function remember(string $key, Closure $compute, int $ttl = self::DEFAULT_TTL): array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $store = $this->cache->getStore();
        if (! method_exists($store, 'lock')) {
            // Driver no soporta locks atómicos (array, file): fallback sin singleflight.
            // En tests está bien; en producción usamos Redis.
            $value = $compute();
            $this->cache->put($key, $value, $ttl);
            return $value;
        }

        $lockKey = $key . ':lock';
        $lock    = $store->lock($lockKey, self::LOCK_TTL_SECONDS); // @phpstan-ignore-line dynamic Redis lock

        try {
            // Block hasta LOCK_WAIT_SECONDS esperando a quien tenga el lock.
            // Si el lock estaba libre, se adquiere inmediatamente.
            $obtained = $lock->block(self::LOCK_WAIT_SECONDS);

            if ($obtained) {
                // Double-check: tal vez otro caller computó mientras esperábamos turno
                $cached = $this->cache->get($key);
                if (is_array($cached)) {
                    return $cached;
                }

                $value = $compute();
                $this->cache->put($key, $value, $ttl);
                return $value;
            }
        } catch (Throwable $e) {
            $this->logger->warning('CacheReportes: lock falló — degradando a cálculo directo', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // ignore — el lock vencerá por TTL
            }
        }

        // Fallback: no obtuvimos el lock en LOCK_WAIT_SECONDS. Computamos sin caché para
        // no bloquear al usuario indefinidamente. Sirve este request pero NO escribe al
        // cache (otro caller debería estar haciéndolo).
        return $compute();
    }

    /**
     * Construye un key estándar de cache para reportes.
     *
     * @param  string                $tipo      'bg'|'er'|'lm'|'bc'
     * @param  string                $tenantId  UUID
     * @param  array<string, mixed>  $partes    componentes específicos del reporte (fechas, cuenta_id, etc.)
     */
    public function buildKey(string $tipo, string $tenantId, array $partes): string
    {
        ksort($partes);
        $hash = substr(sha1(json_encode($partes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 12);

        $partesFlat = [];
        foreach (['fecha_corte', 'desde', 'hasta', 'cuenta_id', 'periodo_codigo'] as $clave) {
            if (isset($partes[$clave])) {
                $partesFlat[] = (string) $partes[$clave];
            }
        }

        $segmento = $partesFlat === [] ? $hash : implode(':', $partesFlat) . ':' . $hash;

        return "tenant:{$tenantId}:{$tipo}:{$segmento}";
    }
}
