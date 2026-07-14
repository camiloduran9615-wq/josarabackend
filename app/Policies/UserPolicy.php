<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->isAdmin() || $actor->is($user);
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin();
    }

    public function changeStatus(User $actor, User $user): bool
    {
        return $actor->isAdmin();
    }

    public function changePassword(User $actor, User $user): bool
    {
        return $actor->is($user);
    }
}
