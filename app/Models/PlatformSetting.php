<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'group',
        'type',
        'value',
        'description',
        'is_sensitive',
        'updated_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_sensitive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'updated_by_platform_admin_id');
    }
}
