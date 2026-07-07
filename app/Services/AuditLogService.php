<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Servicio de escritura de logs de auditoría en BD central.
 *
 * Garantiza:
 *  - Hash chain SHA-256 con bloqueo pesimista por tenant
 *  - Sanitización de PII (passwords, tokens, tarjetas)
 *  - Captura automática de IP, user_agent, request_id, sucursal
 *  - Atomicidad mediante transacción
 *
 * NOTA DE ARQUITECTURA: usamos la conexión nombrada explícitamente
 * ('pgsql' = central) en todo momento. stancl/tenancy cambia la conexión
 * *default* a 'tenant' cuando inicializa un tenant; si dependiéramos de
 * la conexión default, todas las escrituras irían a la BD del tenant.
 */
class AuditLogService
{
    /** Nombre de la conexión de BD central (debe coincidir con DB_CONNECTION). */
    private const CENTRAL_CONNECTION = 'pgsql';
    /** Lista negra global aplicada a old_values y new_values. */
    public const GLOBAL_BLACKLIST = [
        'password',
        'password_hash',
        'password_confirmation',
        'remember_token',
        'api_token',
        'access_token',
        'refresh_token',
        'client_secret',
        'card_number',
        'cvv',
        'cvc',
        'pan',
    ];

    public const CRITICIDAD_INFO = 'info';
    public const CRITICIDAD_WARNING = 'warning';
    public const CRITICIDAD_CRITICAL = 'critical';

    /**
     * Registra una entrada de auditoría.
     */
    public function record(
        string $action,
        string $criticidad = self::CRITICIDAD_INFO,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $motivo = null,
        ?array $metadata = null,
    ): AuditLog {
        $tenantId = $this->resolveTenantId();
        $user = $this->resolveUser();
        $request = request();

        return DB::connection(self::CENTRAL_CONNECTION)
            ->transaction(function () use (
                $tenantId,
                $user,
                $request,
                $action,
                $criticidad,
                $auditable,
                $oldValues,
                $newValues,
                $motivo,
                $metadata
            ): AuditLog {
                $hashAnterior = $this->lastHashFor($tenantId);

                $payload = [
                    'id'                  => (string) Str::uuid(),
                    'tenant_id'           => $tenantId,
                    'user_id'             => $user?->getKey(),
                    'user_email_snapshot' => $user?->email,
                    'user_role_snapshot'  => $user?->role,
                    'action'              => $action,
                    'criticidad'          => $criticidad,
                    'auditable_type'      => $auditable ? $auditable::class : null,
                    'auditable_id'        => $auditable?->getKey() !== null
                        ? (string) $auditable->getKey()
                        : null,
                    'old_values'          => $this->sanitize($oldValues, $auditable),
                    'new_values'          => $this->sanitize($newValues, $auditable),
                    'motivo'              => $motivo,
                    'metadata'            => $metadata,
                    'ip_address'          => $request?->ip() ?? '127.0.0.1',
                    'user_agent'          => mb_substr(
                        (string) ($request?->userAgent() ?? 'system'),
                        0,
                        500
                    ),
                    'request_id'          => $request?->header('X-Request-ID'),
                    'sucursal_id'         => $this->resolveSucursalId($user),
                    'hash_anterior'       => $hashAnterior,
                    'created_at'          => now(),
                ];

                $payload['hash_actual'] = $this->computeHash($payload, $hashAnterior);

                /** @var AuditLog $log */
                $log = AuditLog::query()->create($payload);

                return $log;
            });
    }

    /**
     * Calcula el hash SHA-256 sobre el JSON canónico del payload + hash anterior.
     * Determinista: mismo input siempre produce mismo output.
     */
    public function computeHash(array $payload, ?string $hashAnterior): string
    {
        $clone = $payload;
        unset($clone['hash_actual'], $clone['id']);
        // Normalizar timestamp a string ISO para hash consistente
        if (isset($clone['created_at']) && ! is_string($clone['created_at'])) {
            $clone['created_at'] = (string) $clone['created_at'];
        }
        $this->ksortRecursive($clone);
        $canonical = json_encode(
            $clone,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return hash('sha256', ($canonical ?: '') . ((string) ($hashAnterior ?? '')));
    }

    /**
     * Verifica que la cadena de hashes para un tenant es íntegra.
     * Retorna null si OK, o el ID del primer log con hash inválido.
     */
    public function verifyChainForTenant(string $tenantId): ?string
    {
        $prevHash = null;
        $logs = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        foreach ($logs as $log) {
            $payload = $log->getAttributes();
            $payload['old_values'] = $log->old_values;
            $payload['new_values'] = $log->new_values;
            $payload['metadata'] = $log->metadata;

            $expected = $this->computeHash($payload, $prevHash);
            if (! hash_equals($expected, (string) $log->hash_actual)) {
                return (string) $log->id;
            }
            $prevHash = $log->hash_actual;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Privados
    // -----------------------------------------------------------------------

    private function resolveTenantId(): string
    {
        $tenantId = function_exists('tenant') ? tenant('id') : null;

        if ($tenantId === null) {
            // Fallback para contextos sin tenant (jobs centrales).
            // Se usa el UUID nil para preservar la integridad del schema.
            return '00000000-0000-0000-0000-000000000000';
        }

        return (string) $tenantId;
    }

    private function resolveUser(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    private function resolveSucursalId(?Model $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $sucursalId = $user->sucursal_id ?? null;

        return $sucursalId !== null ? (string) $sucursalId : null;
    }

    private function lastHashFor(string $tenantId): ?string
    {
        return AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('hash_actual');
    }

    /**
     * Aplica la lista negra global y extras del modelo al payload.
     *
     * SEGURIDAD: comparación case-insensitive para evitar que 'Password',
     * 'PASSWORD' o 'Api_Token' escapen la lista negra.
     */
    private function sanitize(?array $values, ?Model $model): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        $blacklist = self::GLOBAL_BLACKLIST;
        if ($model !== null && method_exists($model, 'auditableHidden')) {
            $blacklist = array_merge($blacklist, $model->auditableHidden());
        }

        $blacklistLower = array_map('strtolower', $blacklist);

        $clean = array_filter(
            $values,
            static fn (string $key): bool => ! in_array(strtolower($key), $blacklistLower, true),
            ARRAY_FILTER_USE_KEY,
        );

        return $clean === [] ? null : $clean;
    }

    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }
}
