<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * Roles disponibles en el sistema contable.
     * Basados en normativa colombiana y segregación de funciones DIAN.
     */
    const ROLE_ADMIN     = 'admin';
    const ROLE_CONTADOR  = 'contador';
    const ROLE_AUXILIAR  = 'auxiliar';
    const ROLE_AUDITOR   = 'auditor';
    const ROLE_READONLY  = 'readonly';

    const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_CONTADOR,
        self::ROLE_AUXILIAR,
        self::ROLE_AUDITOR,
        self::ROLE_READONLY,
    ];

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'password',
        'role',
        'activo',
        'last_login',
        'sucursal_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'activo'     => 'boolean',
            'last_login' => 'datetime',
            'password'   => 'hashed',
        ];
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Nombre completo del usuario.
     */
    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    // -------------------------------------------------------------------------
    // Helpers de Roles
    // -------------------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isContador(): bool
    {
        return $this->role === self::ROLE_CONTADOR;
    }

    public function isAuditor(): bool
    {
        return $this->role === self::ROLE_AUDITOR;
    }

    /**
     * Puede aprobar documentos (Contador o Admin).
     */
    public function canApprove(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_CONTADOR]);
    }

    /**
     * Puede anular documentos (solo Contador, por normativa DIAN).
     */
    public function canVoid(): bool
    {
        return $this->role === self::ROLE_CONTADOR;
    }

    /**
     * Puede cerrar un periodo contable.
     */
    public function canClosePeriod(): bool
    {
        return $this->role === self::ROLE_CONTADOR;
    }
}
