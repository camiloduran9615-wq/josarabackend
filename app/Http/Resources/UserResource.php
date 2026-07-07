<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transforma el usuario en un array JSON seguro para la API.
     * Nunca expone el password ni el remember_token.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'nombre'         => $this->nombre,
            'apellido'       => $this->apellido,
            'nombre_completo'=> $this->nombre_completo,
            'email'          => $this->email,
            'role'           => $this->role,
            'role_label'     => $this->roleLabel(),
            'activo'         => $this->activo,
            'last_login'     => $this->last_login?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            // Capacidades del rol (para que el Frontend dibuje el menú correcto)
            'can'            => [
                'approve'      => $this->canApprove(),
                'void'         => $this->canVoid(),
                'close_period' => $this->canClosePeriod(),
                'manage_users' => $this->isAdmin(),
            ],
        ];
    }

    /**
     * Etiqueta legible del rol en español.
     */
    private function roleLabel(): string
    {
        return match ($this->role) {
            'admin'    => 'Administrador',
            'contador' => 'Contador Certificado',
            'auxiliar' => 'Auxiliar Contable',
            'auditor'  => 'Auditor Interno',
            'readonly' => 'Solo Lectura',
            default    => 'Desconocido',
        };
    }
}
