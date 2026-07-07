<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionHistory extends Model
{
    use HasUuids;

    protected $table = 'subscription_history';

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'previous_plan_id',
        'new_plan_id',
        'changed_by_platform_admin_id',
        'reason',
        'observation',
        'effective_mode',
        'effective_at',
        'overuse_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'overuse_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
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

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function previousPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'previous_plan_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function newPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'new_plan_id');
    }
}
