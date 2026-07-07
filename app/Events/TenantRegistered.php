<?php

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantRegistered
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $registrationData
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly array $registrationData,
    ) {}
}
