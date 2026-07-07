<?php

declare(strict_types=1);

namespace App\Http\Requests\LibroMayor;

use App\Policies\ReportePolicy;
use Illuminate\Foundation\Http\FormRequest;

class LibroMayorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewLibroMayor', ReportePolicy::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'periodo_id'      => ['nullable', 'uuid'],
            'tercero_id'      => ['nullable', 'uuid'],
            'centro_costo_id' => ['nullable', 'uuid'],
            'sucursal_id'     => ['nullable', 'uuid'],
            'desde'           => ['nullable', 'date_format:Y-m-d'],
            'hasta'           => ['nullable', 'date_format:Y-m-d', 'gte:desde'],
            'page'            => ['nullable', 'integer', 'min:1'],
            'per_page'        => ['nullable', 'integer', 'min:10', 'max:500'],
        ];
    }
}
