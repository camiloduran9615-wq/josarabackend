<?php

declare(strict_types=1);

namespace App\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base abstracta de eventos de dominio de la plataforma.
 *
 * Captura automáticamente el contexto de ejecución (tenant_id, user_id, request_id,
 * occurred_at) para que cualquier Listener que persista al AuditLog o haga emisión
 * a n8n tenga la metadata necesaria sin repetir lógica en cada evento concreto.
 *
 * Los eventos legacy (App\Events\Asiento\*, App\Events\Periodo\*) se mantienen como
 * clases planas por compatibilidad. Los eventos NUEVOS de la épica EPIC-LMB-001
 * heredan de aquí.
 *
 * Note: SerializesModels is intentionally omitted — it conflicts with PHP 8.4
 * readonly properties. All listeners for DomainEvents are synchronous (no queue).
 */
abstract class DomainEvent
{
    use Dispatchable;

    public readonly string $occurredAt;
    public readonly ?string $tenantId;
    public readonly ?string $userId;
    public readonly ?string $requestId;

    public function __construct()
    {
        $this->occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED);

        // Captura el tenant activo (stancl/tenancy expone helper global)
        $this->tenantId = function_exists('tenant') && tenant() !== null
            ? (string) tenant('id')
            : null;

        $this->userId = auth()->check() ? (string) auth()->id() : null;

        // request_id correlaciona logs HTTP con eventos de dominio
        $request = request();
        $this->requestId = $request->header('X-Request-ID') ?? $request->header('X-Correlation-ID');
    }

    /**
     * Slug canónico del evento — usado en AuditLog.action y para keys de caché.
     * Default: domain-prefix derivado del FQN. Override en hijos para tag custom.
     */
    public function nombre(): string
    {
        $base = class_basename(static::class);

        // CamelCase → snake_case
        $snake = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $base));

        return $snake;
    }
}
