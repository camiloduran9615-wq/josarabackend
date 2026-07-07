<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class PlatformAdmin extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SUPPORT_ADMIN = 'support_admin';
    public const ROLE_BILLING_ADMIN = 'billing_admin';
    public const ROLE_READONLY_ADMIN = 'readonly_admin';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_SUPPORT_ADMIN,
        self::ROLE_BILLING_ADMIN,
        self::ROLE_READONLY_ADMIN,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<PlatformAdminAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PlatformAdminAuditLog::class);
    }

    public function canManagePlatform(): bool
    {
        return $this->active && $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function canReadPlatform(): bool
    {
        return $this->active && in_array($this->role, self::ROLES, true);
    }
}
