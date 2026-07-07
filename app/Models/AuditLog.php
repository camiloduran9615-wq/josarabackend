<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AuditLog (BD central, append-only).
 *
 * Esta tabla NO acepta UPDATE ni DELETE — la integridad se enforce en tres capas:
 *   1) Aplicación: este modelo lanza LogicException
 *   2) Base de datos: trigger PostgreSQL audit_logs_no_update_delete
 *   3) Privilegios: el rol saas_app solo tiene SELECT,INSERT (ver secure_audit_logs.sql)
 *
 * Cumple Resolución DIAN 000042/2020 + art. 28 Código de Comercio (10 años).
 *
 * @property string      $id
 * @property string      $tenant_id
 * @property string|null $user_id
 * @property string|null $user_email_snapshot
 * @property string|null $user_role_snapshot
 * @property string      $action
 * @property string      $criticidad
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property array|null  $old_values
 * @property array|null  $new_values
 * @property string|null $motivo
 * @property array|null  $metadata
 * @property string      $ip_address
 * @property string      $user_agent
 * @property string|null $request_id
 * @property string|null $sucursal_id
 * @property string|null $hash_anterior
 * @property string      $hash_actual
 * @property \Carbon\CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    use HasUuids;

    /**
     * Conexión fija a la BD central (pgsql).
     *
     * CRÍTICO: stancl/tenancy cambia la conexión *default* a 'tenant' cuando
     * inicializa un tenant. Sin esta propiedad, Eloquent usaría la BD del
     * tenant y nunca encontraría la tabla audit_logs central.
     *
     * Debe coincidir con config('tenancy.database.central_connection'),
     * que a su vez lee env('DB_CONNECTION') = 'pgsql'.
     */
    protected $connection = 'pgsql';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'user_id',
        'user_email_snapshot',
        'user_role_snapshot',
        'action',
        'criticidad',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'motivo',
        'metadata',
        'ip_address',
        'user_agent',
        'request_id',
        'sucursal_id',
        'hash_anterior',
        'hash_actual',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values'  => 'array',
            'new_values'  => 'array',
            'metadata'    => 'array',
            'created_at'  => 'immutable_datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Append-only — segunda barrera (la primera es el trigger PG)
    // -----------------------------------------------------------------------

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('AuditLog es append-only: no se puede actualizar.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('AuditLog es append-only: no se puede eliminar.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException('AuditLog es append-only: forceDelete prohibido.');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        // user_id apunta al usuario en la BD del tenant; este BelongsTo solo
        // resuelve si la relación se carga desde el contexto tenant correcto.
        return $this->belongsTo(User::class);
    }
}
