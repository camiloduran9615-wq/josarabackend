<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [User::ROLE_ADMIN, User::ROLE_AUDITOR], true);
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function verifyChain(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }
}
