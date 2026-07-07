<?php

declare(strict_types=1);

namespace App\Events\Asiento;

use App\Models\Tenant\Asiento;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AsientoReversado
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Asiento $original,
        public readonly Asiento $reverso,
        public readonly User $reverser,
        public readonly string $motivo,
    ) {}
}
