<?php

declare(strict_types=1);

namespace App\Events\Asiento;

use App\Models\Tenant\Asiento;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AsientoAnulado
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Asiento $asiento,
        public readonly User $voider,
        public readonly string $motivo,
    ) {}
}
