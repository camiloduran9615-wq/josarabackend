<?php

declare(strict_types=1);

namespace App\Http\Requests\Periodo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paso 2 del flujo dual: admin aprueba la reapertura.
 */
class ReopenApproveRequest extends FormRequest
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

        return $this->user()?->can('approveReopen', $periodo) ?? false;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
        ];
    }
}
