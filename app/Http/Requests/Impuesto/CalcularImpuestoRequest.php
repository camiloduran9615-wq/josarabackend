<?php

declare(strict_types=1);

namespace App\Http\Requests\Impuesto;

use App\Models\Tenant\Impuesto;
use Illuminate\Foundation\Http\FormRequest;

class CalcularImpuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('calcular', Impuesto::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base'             => ['required', 'numeric', 'min:0'],
            'codigo_impuesto'  => ['required', 'string', 'max:20'],
            'fecha'            => ['nullable', 'date_format:Y-m-d'],
            'municipio_dane'   => ['nullable', 'string', 'max:10'],
            'actividad_ciiu'   => ['nullable', 'string', 'max:10'],
        ];
    }
}
