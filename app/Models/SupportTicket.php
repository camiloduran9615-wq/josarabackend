<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'subject',
        'status',
        'priority',
        'requester_email',
        'assigned_to_platform_admin_id',
        'created_by_platform_admin_id',
        'last_activity_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_to_platform_admin_id');
    }
}
