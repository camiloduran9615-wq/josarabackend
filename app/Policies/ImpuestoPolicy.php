<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant\Impuesto;
use App\Models\User;

/**
 * Política para el catálogo de impuestos.
 *
 * Las tarifas `sistema = true` (sembradas por ImpuestosSeeder) son
 * de solo-lectura para todos; no pueden editarse ni eliminarse.
 */
class ImpuestoPolicy
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

    public function view(User $user, Impuesto $impuesto): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
        ], true);
    }

    public function update(User $user, Impuesto $impuesto): bool
    {
        if ($impuesto->sistema) {
            return false;
        }

        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
        ], true);
    }

    public function delete(User $user, Impuesto $impuesto): bool
    {
        if ($impuesto->sistema) {
            return false;
        }

        return $user->role === User::ROLE_ADMIN;
    }

    public function calcular(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
            User::ROLE_AUXILIAR,
        ], true);
    }
}
