<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformOperationEvent extends Model
{
    use HasUuids;

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'category',
        'severity',
        'title',
        'message',
        'source',
        'target_type',
        'target_id',
        'metadata',
        'acknowledged_at',
        'acknowledged_by_platform_admin_id',
        'resolved_at',
        'resolved_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'acknowledged_by_platform_admin_id');
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'resolved_by_platform_admin_id');
    }
}
