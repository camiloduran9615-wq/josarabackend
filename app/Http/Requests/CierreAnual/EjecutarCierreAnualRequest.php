<?php

declare(strict_types=1);

namespace App\Http\Requests\CierreAnual;

use App\Policies\ReportePolicy;
use Illuminate\Foundation\Http\FormRequest;

class EjecutarCierreAnualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ejecutarCierreAnual', ReportePolicy::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmar' => ['required', 'boolean', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmar.accepted' => 'Debe confirmar explícitamente la ejecución del cierre anual.',
        ];
    }
}
