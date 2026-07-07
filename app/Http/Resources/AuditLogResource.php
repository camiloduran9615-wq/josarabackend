<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'tenant_id'  => $this->tenant_id,
            'action'     => $this->action,
            'criticidad' => $this->criticidad,
            'user' => [
                'id'             => $this->user_id,
                'email_snapshot' => $this->user_email_snapshot,
                'role_snapshot'  => $this->user_role_snapshot,
            ],
            'auditable' => [
                'type' => $this->auditable_type ? class_basename((string) $this->auditable_type) : null,
                'id'   => $this->auditable_id,
            ],
            'old_values' => $this->when(
                $request->routeIs('*.show'),
                $this->old_values
            ),
            'new_values' => $this->when(
                $request->routeIs('*.show'),
                $this->new_values
            ),
            'motivo'     => $this->motivo,
            'metadata'   => $this->when($request->routeIs('*.show'), $this->metadata),
            'ip'         => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
