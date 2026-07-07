<?php

declare(strict_types=1);

namespace App\Http\Requests\Reportes;

use App\Policies\ReportePolicy;
use Illuminate\Foundation\Http\FormRequest;

class BalanceGeneralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewBalanceGeneral', ReportePolicy::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_corte'              => ['required', 'date_format:Y-m-d'],
            'comparativo_año_anterior' => ['nullable', 'boolean'],
        ];
    }
}
