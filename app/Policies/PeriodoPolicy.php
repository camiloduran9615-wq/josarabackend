<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant\PeriodoContable;
use App\Models\User;

class PeriodoPolicy
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

    public function view(User $user, PeriodoContable $periodo): bool
    {
        return $this->viewAny($user);
    }

    public function close(User $user, PeriodoContable $periodo): bool
    {
        return $user->role === User::ROLE_CONTADOR
            && $periodo->estaAbierto();
    }

    public function requestReopen(User $user, PeriodoContable $periodo): bool
    {
        return $user->role === User::ROLE_CONTADOR
            && $periodo->estado === PeriodoContable::ESTADO_CERRADO;
    }

    public function approveReopen(User $user, PeriodoContable $periodo): bool
    {
        return $user->role === User::ROLE_ADMIN
            && $periodo->estado === PeriodoContable::ESTADO_CERRADO;
    }

    public function lockFiscal(User $user, PeriodoContable $periodo): bool
    {
        return $user->role === User::ROLE_ADMIN
            && $periodo->estado === PeriodoContable::ESTADO_CERRADO;
    }
}
