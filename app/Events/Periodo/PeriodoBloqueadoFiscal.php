<?php

declare(strict_types=1);

namespace App\Events\Periodo;

use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PeriodoBloqueadoFiscal
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PeriodoContable $periodo,
        public readonly User $admin,
    ) {}
}
