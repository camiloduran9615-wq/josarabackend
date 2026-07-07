<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Política de acceso a reportes financieros.
 *
 * Matriz de roles (Arquitecto §10):
 *   admin, contador, auxiliar, auditor, readonly → pueden ver reportes
 *   Solo contador/admin → pueden ejecutar cierre anual
 */
class ReportePolicy
{
    public function viewLibroMayor(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
            User::ROLE_AUXILIAR,
            User::ROLE_AUDITOR,
            User::ROLE_READONLY,
        ], true);
    }

    public function viewBalanceGeneral(User $user): bool
    {
        return $this->viewLibroMayor($user);
    }

    public function viewEstadoResultados(User $user): bool
    {
        return $this->viewLibroMayor($user);
    }

    public function viewBalanceComprobacion(User $user): bool
    {
        return $this->viewLibroMayor($user);
    }

    public function ejecutarCierreAnual(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_CONTADOR,
        ], true);
    }
}
