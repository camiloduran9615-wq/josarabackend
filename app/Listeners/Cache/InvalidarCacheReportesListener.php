<?php

declare(strict_types=1);

namespace App\Listeners\Cache;

use App\Events\Asiento\AsientoAnulado;
use App\Events\Asiento\AsientoAprobado;
use App\Events\Asiento\AsientoReversado;
use App\Events\CierreAnual\CierreAnualEjecutado;
use App\Events\Periodo\PeriodoCerrado;
use App\Events\Periodo\PeriodoReabierto;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Invalida cache Redis de reportes cuando ocurren eventos que afectan saldos.
 *
 * Estrategia de keys (debe coincidir con la usada por los Services de reportes):
 *   tenant:{tenant_id}:bg:*                     Balance General
 *   tenant:{tenant_id}:er:*                     Estado de Resultados
 *   tenant:{tenant_id}:lm:{cuenta_id}:*         Libro Mayor por cuenta
 *   tenant:{tenant_id}:bc:{periodo_codigo}      Balance de Comprobación por periodo
 *
 * Granularidad:
 *  - AsientoAprobado/Anulado/Reversado: purge selectivo por cuenta + global por tenant
 *  - PeriodoCerrado/Reabierto/CierreAnualEjecutado: purge TOTAL del tenant
 *
 * Esta clase tiene UN solo handle() polimórfico — Laravel lo invoca con el evento.
 * Despachamos por instanceof para evitar 6 listeners idénticos.
 */
final class InvalidarCacheReportesListener
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function handle(object $event): void
    {
        $tenantId = $this->resolveTenantId($event);
        if ($tenantId === null) {
            return; // sin contexto tenant, no podemos invalidar
        }

        match (true) {
            $event instanceof AsientoAprobado,
            $event instanceof AsientoAnulado,
            $event instanceof AsientoReversado    => $this->invalidarPorAsiento($tenantId, $event),

            $event instanceof PeriodoCerrado,
            $event instanceof PeriodoReabierto,
            $event instanceof CierreAnualEjecutado => $this->invalidarTotalTenant($tenantId),

            default => null,
        };
    }

    private function resolveTenantId(object $event): ?string
    {
        // Eventos heredados de DomainEvent traen tenantId capturado
        if (property_exists($event, 'tenantId') && $event->tenantId !== null) {
            return (string) $event->tenantId;
        }

        // Fallback: tenant() helper de stancl
        if (function_exists('tenant') && tenant() !== null) {
            return (string) tenant('id');
        }

        return null;
    }

    /**
     * Para eventos de asiento: invalida libro mayor de cada cuenta tocada + reportes globales.
     *
     * AsientoReversado lleva DOS asientos (original + reverso) — recolectamos cuentas
     * de ambos porque sus líneas pueden tocar cuentas distintas.
     */
    private function invalidarPorAsiento(string $tenantId, AsientoAprobado|AsientoAnulado|AsientoReversado $event): void
    {
        // phpstan no calcula la intersección de propiedades en una unión de eventos;
        // narrow explícito por instanceof para mantener type-safety.
        /** @var list<\App\Models\Tenant\Asiento> $asientos */
        $asientos = match (true) {
            $event instanceof AsientoAprobado  => [$event->asiento],
            $event instanceof AsientoAnulado   => [$event->asiento],
            $event instanceof AsientoReversado => [$event->original, $event->reverso],
        };

        // Cargar IDs de cuenta tocadas por las líneas de TODOS los asientos del evento
        $cuentaIds = [];
        foreach ($asientos as $asiento) {
            foreach ($asiento->lineas()->pluck('cuenta_id') as $cuentaId) {
                $cuentaIds[(string) $cuentaId] = true;
            }
        }

        // Borrar caches por cuenta (libro mayor)
        foreach (array_keys($cuentaIds) as $cuentaId) {
            $this->forgetMatching("tenant:{$tenantId}:lm:{$cuentaId}:");
        }

        // Borrar reportes globales del tenant
        $this->forgetMatching("tenant:{$tenantId}:bg:");
        $this->forgetMatching("tenant:{$tenantId}:er:");
        $this->forgetMatching("tenant:{$tenantId}:bc:");
    }

    private function invalidarTotalTenant(string $tenantId): void
    {
        $this->forgetMatching("tenant:{$tenantId}:");
    }

    /**
     * Borra todas las claves que matcheen el prefix.
     *
     * En Redis usa SCAN+DEL (no KEYS — bloqueante en prod). Cuando el driver no es
     * Redis (e.g. tests con array driver), simplemente flusha el store (aceptable
     * porque tests aíslan tenants).
     */
    private function forgetMatching(string $prefix): void
    {
        $store = $this->cache->getStore();

        // Solo el Redis store de Laravel expone connection() — fallback si no es Redis
        if (! method_exists($store, 'connection')) {
            $this->cache->flush();
            return;
        }

        try {
            $redis = $store->connection();
            $pattern = $prefix . '*';
            $cursor = '0';

            do {
                // Laravel Redis connection: scan($cursor, 'MATCH', $pattern, 'COUNT', $count)
                // devuelve [string $newCursor, list<string> $keys] o false en error.
                $result = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 200); // @phpstan-ignore-line dynamic Redis API

                if ($result === false || ! is_array($result)) {
                    break;
                }

                $cursor = (string) $result[0];
                /** @var list<string> $keys */
                $keys = is_array($result[1]) ? $result[1] : [];

                if ($keys !== []) {
                    $redis->del($keys); // @phpstan-ignore-line dynamic Redis API
                }
            } while ($cursor !== '0');
        } catch (\Throwable) {
            // Fallback seguro: ante cualquier fallo del driver, sobre-invalidamos
            // (mejor cache fría que stale data).
            $this->cache->flush();
        }
    }
}
