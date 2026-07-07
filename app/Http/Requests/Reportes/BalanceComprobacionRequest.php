<?php

declare(strict_types=1);

namespace App\Http\Requests\Reportes;

use App\Policies\ReportePolicy;
use Illuminate\Foundation\Http\FormRequest;

class BalanceComprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewBalanceComprobacion', ReportePolicy::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'periodo_id' => ['required', 'uuid'],
            // 1=Clase, 2=Grupo, 3=Cuenta (4 díg), 4=Subcuenta (6 díg PUC colombiano)
            'nivel'      => ['nullable', 'integer', 'in:1,2,3,4'],
        ];
    }
}
