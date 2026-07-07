<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'tenant_status_history';

    protected $fillable = [
        'tenant_id',
        'previous_status',
        'new_status',
        'changed_by_platform_admin_id',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'changed_by_platform_admin_id');
    }
}
