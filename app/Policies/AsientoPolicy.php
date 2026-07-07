<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant\Asiento;
use App\Models\User;

/**
 * Policy de Asientos: implementa la matriz NIIF + segregación de funciones.
 * Ver §5.1 del diseño y §9 de reglas_asientos_auditoria.md.
 */
class AsientoPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
            User::ROLE_AUXILIAR,
            User::ROLE_AUDITOR,
            User::ROLE_READONLY,
        ], true);
    }

    public function view(User $user, Asiento $asiento): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
            User::ROLE_AUXILIAR,
        ], true);
    }

    public function update(User $user, Asiento $asiento): bool
    {
        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            return false;
        }
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_CONTADOR], true)) {
            return true;
        }

        return $user->role === User::ROLE_AUXILIAR
            && (string) $asiento->created_by_id === (string) $user->id;
    }

    /**
     * Aprobar requiere rol contador o admin.
     *
     * Segregación de funciones (NIA 315 / COSO §9 reglas_asientos_auditoria.md):
     *   - CONTADOR: nunca puede aprobar su propio asiento (segregación estricta).
     *   - ADMIN:    SÍ puede aprobar sus propios asientos (override para PYME unipersonal).
     *   - Otros:    no pueden aprobar.
     *
     * El override admin es la práctica estándar en software contable colombiano
     * (SIIGO, World Office, Helisa) — un Administrador asume la responsabilidad
     * legal de la doble función bajo su propio criterio.
     */
    public function approve(User $user, Asiento $asiento): bool
    {
        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            return false;
        }
        if (!in_array($user->role, [User::ROLE_CONTADOR, User::ROLE_ADMIN], true)) {
            return false;
        }

        // Admin tiene full control — incluye sus propios asientos.
        if ($user->role === User::ROLE_ADMIN) {
            return true;
        }

        // Contador: aplica segregación estricta salvo flag de exoneración del tenant.
        $isOwnWork = (string) $asiento->created_by_id === (string) $user->id
            || (string) $asiento->last_modified_by_id === (string) $user->id;

        if ($isOwnWork) {
            return (bool) (function_exists('tenant')
                ? tenant('segregacion_funciones_exonerada')
                : false);
        }

        return true;
    }

    public function void(User $user, Asiento $asiento): bool
    {
        if ($asiento->estado !== Asiento::ESTADO_APROBADO) {
            return false;
        }
        if ($user->role !== User::ROLE_CONTADOR) {
            return false;
        }
        $periodo = $asiento->periodo;

        return $periodo !== null && $periodo->estaAbierto();
    }

    public function reverse(User $user, Asiento $asiento): bool
    {
        return $asiento->estado === Asiento::ESTADO_APROBADO
            && $user->role === User::ROLE_CONTADOR;
    }

    public function discard(User $user, Asiento $asiento): bool
    {
        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            return false;
        }
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_CONTADOR], true)) {
            return true;
        }

        return $user->role === User::ROLE_AUXILIAR
            && (string) $asiento->created_by_id === (string) $user->id;
    }
}
