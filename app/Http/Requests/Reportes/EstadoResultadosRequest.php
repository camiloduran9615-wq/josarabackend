<?php

declare(strict_types=1);

namespace App\Http\Requests\Reportes;

use App\Policies\ReportePolicy;
use Illuminate\Foundation\Http\FormRequest;

class EstadoResultadosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewEstadoResultados', ReportePolicy::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'desde'       => ['required', 'date_format:Y-m-d'],
            'hasta'       => ['required', 'date_format:Y-m-d', 'gte:desde'],
            'comparativo' => ['nullable', 'boolean'],
        ];
    }
}
