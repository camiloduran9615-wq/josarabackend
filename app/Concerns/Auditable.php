<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Observers\AuditableObserver;

/**
 * Marca un modelo Eloquent como auditable.
 * El AuditableObserver intercepta created/updated/deleted y delega
 * a AuditLogService para registrar en la BD central.
 *
 * Para sobreescribir el prefijo de acción (ej: 'asiento.created'),
 * implementa auditableActionPrefix() en el modelo.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditableObserver::class);
    }

    /**
     * Etiqueta legible del registro para mostrar en la UI de auditoría.
     */
    public function auditableLabel(): string
    {
        return $this->numero
            ?? $this->codigo
            ?? $this->nombre
            ?? (string) ($this->id ?? '');
    }

    /**
     * Prefijo de la acción semántica. Ej: 'asiento' produce
     * eventos 'asiento.created', 'asiento.updated', 'asiento.deleted'.
     */
    public function auditableActionPrefix(): string
    {
        return strtolower(class_basename(static::class));
    }

    /**
     * Campos extra a excluir del diff (además de la lista negra global).
     */
    public function auditableHidden(): array
    {
        return [];
    }
}
