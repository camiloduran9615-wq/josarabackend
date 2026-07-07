<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer aplicado por el trait Auditable.
 * Convierte eventos created/updated/deleted en entradas de AuditLog.
 *
 * NOTA: para acciones de dominio (aprobar, anular, reversar, cerrar periodo)
 * NO se usa este observer — se usan eventos custom + listeners
 * (ver app/Listeners/Audit/).
 */
class AuditableObserver
{
    public function __construct(private readonly AuditLogService $logger) {}

    public function created(Model $model): void
    {
        $action = $this->prefix($model) . '.created';
        $this->logger->record(
            action: $action,
            criticidad: AuditLogService::CRITICIDAD_INFO,
            auditable: $model,
            newValues: $this->safeAttrs($model, $model->getAttributes()),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        if ($changes === []) {
            return;
        }

        $original = array_intersect_key($model->getOriginal(), $changes);

        $this->logger->record(
            action: $this->prefix($model) . '.updated',
            criticidad: AuditLogService::CRITICIDAD_INFO,
            auditable: $model,
            oldValues: $this->safeAttrs($model, $original),
            newValues: $this->safeAttrs($model, $changes),
        );
    }

    public function deleted(Model $model): void
    {
        $this->logger->record(
            action: $this->prefix($model) . '.deleted',
            criticidad: AuditLogService::CRITICIDAD_WARNING,
            auditable: $model,
            oldValues: $this->safeAttrs($model, $model->getOriginal()),
        );
    }

    private function prefix(Model $model): string
    {
        return method_exists($model, 'auditableActionPrefix')
            ? $model->auditableActionPrefix()
            : strtolower(class_basename($model));
    }

    /**
     * Convierte valores complejos (Carbon, etc.) a primitivos para el JSON.
     */
    private function safeAttrs(Model $model, array $attrs): array
    {
        $out = [];
        foreach ($attrs as $k => $v) {
            if (is_object($v) && method_exists($v, '__toString')) {
                $out[$k] = (string) $v;
            } elseif (is_object($v)) {
                $out[$k] = json_decode(json_encode($v) ?: 'null', true);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
