<?php

declare(strict_types=1);

namespace App\Http\Requests\Periodo;

use Illuminate\Foundation\Http\FormRequest;

class ClosePeriodoRequest extends FormRequest
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

        return $this->user()?->can('close', $periodo) ?? false;
    }

    public function rules(): array
    {
        return [
            'confirmar' => ['required', 'boolean', 'accepted'],
            'motivo'    => ['nullable', 'string', 'max:1000'],
        ];
    }
}
