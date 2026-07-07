<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'users_count',
        'cost_centers_count',
        'warehouses_count',
        'requisitions_month_count',
        'quotes_month_count',
        'purchase_orders_month_count',
        'invoices_month_count',
        'products_count',
        'third_parties_count',
        'storage_bytes',
        'api_requests_month_count',
        'snapshot_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_at' => 'datetime',
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
}
