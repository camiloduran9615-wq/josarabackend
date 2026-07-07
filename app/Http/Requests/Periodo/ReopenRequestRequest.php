<?php

declare(strict_types=1);

namespace App\Http\Requests\Periodo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paso 1 del flujo dual: contador solicita reapertura.
 */
class ReopenRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('id');
        if (! $id) {
            return false;
        }
        $periodo = \App\Models\Tenant\PeriodoContable::query()->find((string) $id);
        if ($periodo === null) {
            return false;
        }

        return $this->user()?->can('requestReopen', $periodo) ?? false;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:50', 'max:2000'],
        ];
    }
}
