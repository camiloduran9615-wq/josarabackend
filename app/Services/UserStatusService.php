<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserStatusService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function setStatus(User $actor, User $target, bool $active): User
    {
        $oldStatus = (bool) $target->activo;

        /** @var User $updated */
        $updated = DB::connection('tenant')->transaction(function () use ($actor, $target, $active): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($actor->is($lockedUser) && ! $active) {
                throw ValidationException::withMessages([
                    'activo' => ['No puedes inactivar tu propia cuenta.'],
                ]);
            }

            if (! $active && $lockedUser->isAdmin()) {
                $activeAdmins = User::query()
                    ->where('role', User::ROLE_ADMIN)
                    ->where('activo', true)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count();

                if ($activeAdmins <= 1) {
                    throw ValidationException::withMessages([
                        'activo' => ['No puedes inactivar el último administrador activo.'],
                    ]);
                }
            }

            if ((bool) $lockedUser->activo === $active) {
                return $lockedUser;
            }

            $lockedUser->update(['activo' => $active]);

            if (! $active) {
                $lockedUser->tokens()->delete();
            }

            return $lockedUser->fresh();
        });

        if ($oldStatus !== (bool) $updated->activo) {
            $this->auditLog->record(
                action: $updated->activo ? 'user.activated' : 'user.deactivated',
                criticidad: $updated->activo
                    ? AuditLogService::CRITICIDAD_INFO
                    : AuditLogService::CRITICIDAD_WARNING,
                auditable: $updated,
                oldValues: ['activo' => $oldStatus],
                newValues: ['activo' => (bool) $updated->activo],
            );
        }

        return $updated;
    }
}
