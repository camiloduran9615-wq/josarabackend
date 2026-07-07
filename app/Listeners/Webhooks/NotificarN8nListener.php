<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\CierreAnual\CierreAnualEjecutado;
use App\Events\Periodo\PeriodoCerrado;
use App\Events\Periodo\PeriodoReabierto;
use App\Events\Saldos\SaldosInconsistenciaDetectada;
use App\Models\Tenant as TenantCentralModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispara webhook HTTP a n8n para eventos de alto interés operativo.
 *
 * ÚNICO listener que es ASÍNCRONO (`ShouldQueue`) — la disponibilidad de n8n NO debe
 * bloquear la operación contable. Si n8n está caído, el evento queda en cola para retry.
 *
 * URL del webhook: leída de `tenants.meta->n8n_webhook_url` (decisión PM día 1).
 *   - Si el tenant NO configuró URL, el listener no hace nada (silent skip).
 *   - Si configuró, envía POST con payload firmado por secret compartido.
 *
 * Auth: HMAC SHA-256 del body con secret `tenants.meta->n8n_webhook_secret`.
 *   Header: `X-SaaS-Signature: sha256={hash}`
 *
 * Reintentos: cola Laravel maneja retries (max 3, backoff exponencial).
 * Failure: tras 3 intentos fallidos, queue::failed_jobs lo persiste para inspección.
 */
final class NotificarN8nListener implements ShouldQueue
{
    use InteractsWithQueue;

    /** Reintentos antes de dar por fallido el envío. */
    public int $tries = 3;

    /**
     * Backoff exponencial: 30s, 2min, 10min.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(object $event): void
    {
        $tenantId = $this->resolveTenantId($event);
        if ($tenantId === null) {
            return;
        }

        $config = $this->cargarConfigWebhook($tenantId);
        if ($config === null) {
            return; // tenant no configuró webhook
        }

        $payload = $this->buildPayload($event);
        $body    = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $body, $config['secret']);

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'Content-Type'      => 'application/json',
                    'X-SaaS-Tenant'     => $tenantId,
                    'X-SaaS-Event'      => $payload['event'],
                    'X-SaaS-Signature'  => 'sha256=' . $signature,
                ])
                ->post($config['url'], $payload);

            if ($response->failed()) {
                $this->logger->warning('n8n webhook respondió con error', [
                    'tenant_id' => $tenantId,
                    'event'     => $payload['event'],
                    'status'    => $response->status(),
                    'body'      => mb_substr((string) $response->body(), 0, 500),
                ]);
                // Lanzar para que el job se reintente desde la cola
                throw new \RuntimeException("n8n webhook returned HTTP {$response->status()}");
            }
        } catch (Throwable $e) {
            $this->logger->warning('n8n webhook falló', [
                'tenant_id' => $tenantId,
                'event'     => $payload['event'],
                'error'     => $e->getMessage(),
            ]);
            throw $e; // re-throw → retry policy del job kicks in
        }
    }

    private function resolveTenantId(object $event): ?string
    {
        if (property_exists($event, 'tenantId') && $event->tenantId !== null) {
            return (string) $event->tenantId;
        }
        if (function_exists('tenant') && tenant() !== null) {
            return (string) tenant('id');
        }
        return null;
    }

    /**
     * @return array{url: string, secret: string}|null
     */
    private function cargarConfigWebhook(string $tenantId): ?array
    {
        // Acceso a BD central — el modelo Tenant vive ahí
        $tenant = TenantCentralModel::query()->find($tenantId);
        if ($tenant === null) {
            return null;
        }

        /** @var array<string,mixed> $meta */
        $meta = (array) ($tenant->data ?? []); // stancl/tenancy guarda meta en `data`

        $url    = is_string($meta['n8n_webhook_url']    ?? null) ? $meta['n8n_webhook_url']    : null;
        $secret = is_string($meta['n8n_webhook_secret'] ?? null) ? $meta['n8n_webhook_secret'] : null;

        if ($url === null || $url === '' || $secret === null || $secret === '') {
            return null;
        }

        return ['url' => $url, 'secret' => $secret];
    }

    /**
     * Construye un payload uniforme para n8n.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(object $event): array
    {
        $base = [
            'event'       => $this->slugEvento($event),
            'occurred_at' => property_exists($event, 'occurredAt')
                ? $event->occurredAt
                : (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'tenant_id'   => $this->resolveTenantId($event),
            'request_id'  => property_exists($event, 'requestId') ? $event->requestId : null,
        ];

        return match (true) {
            $event instanceof SaldosInconsistenciaDetectada => array_merge($base, [
                'criticidad' => 'critical',
                'data' => [
                    'periodo_id'         => $event->resultado->periodoId,
                    'filas_comparadas'   => $event->resultado->filasComparadas,
                    'anomalias_count'    => $event->resultado->anomaliasCount,
                    'delta_debito_total' => $event->resultado->deltaDebitoTotal,
                    'delta_credito_total'=> $event->resultado->deltaCreditoTotal,
                    'duracion_segundos'  => $event->resultado->duracionSegundos(),
                ],
            ]),

            $event instanceof PeriodoCerrado => array_merge($base, [
                'criticidad' => 'warning',
                'data' => [
                    'periodo_id'    => $event->periodo->id,
                    'cerrado_por'   => $event->closer->id,
                    'motivo'        => $event->motivo,
                ],
            ]),

            $event instanceof PeriodoReabierto => array_merge($base, [
                'criticidad' => 'critical',
                'data' => [
                    'periodo_id'    => $event->periodo->id,
                    'reabierto_por' => $event->reopener->id ?? null,
                    'motivo'        => $event->motivo,
                ],
            ]),

            $event instanceof CierreAnualEjecutado => array_merge($base, [
                'criticidad' => 'critical',
                'data' => [
                    'anio'             => $event->anio,
                    'resultado'        => $event->resultado,
                    'monto'            => $event->montoResultado,
                    'asientos_count'   => count($event->asientos),
                ],
            ]),

            default => array_merge($base, ['criticidad' => 'info', 'data' => []]),
        };
    }

    private function slugEvento(object $event): string
    {
        if (method_exists($event, 'nombre')) {
            return (string) $event->nombre();
        }
        $base = class_basename($event::class);
        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $base));
    }
}
