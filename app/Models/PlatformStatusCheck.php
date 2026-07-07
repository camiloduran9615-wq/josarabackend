<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformStatusCheck extends Model
{
    use HasUuids;

    protected $fillable = [
        'service_key',
        'service_name',
        'status',
        'latency_ms',
        'region',
        'message',
        'metadata',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'metadata' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
