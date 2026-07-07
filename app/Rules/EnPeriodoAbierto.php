<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Tenant\PeriodoContable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida que la fecha caiga en un periodo contable abierto.
 * Si el periodo no existe aún, se considera "abierto" (se creará al usar).
 */
class EnPeriodoAbierto implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $fecha = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            $fail("El campo {$attribute} no es una fecha válida.");
            return;
        }

        $codigo = sprintf('%04d-%02d', $fecha->year, $fecha->month);

        $periodo = PeriodoContable::query()
            ->where('codigo', $codigo)
            ->first();

        if ($periodo !== null && ! $periodo->estaAbierto()) {
            $fail("La fecha cae en el periodo {$codigo} que está {$periodo->estado}.");
        }
    }
}
