<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud efímera de aprobación dual (BD central).
 * Casos: reapertura de periodo, anulación de factura DIAN aceptada.
 * TTL: 30 minutos. Job purge_expired_dual_approvals limpia diariamente.
 */
class DualApproval extends Model
{
    use HasUuids;

    protected $connection = 'pgsql';

    public const ACTION_PERIODO_REOPEN = 'periodo.reopen';
    public const ACTION_FACTURA_DIAN_VOID = 'factura.dian_void';

    protected $fillable = [
        'tenant_id',
        'action',
        'subject_type',
        'subject_id',
        'requested_by_id',
        'payload',
        'motivo',
        'expires_at',
        'approved_at',
        'approved_by_id',
    ];

    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'expires_at'  => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isApproved() && ! $this->isExpired();
    }
}
