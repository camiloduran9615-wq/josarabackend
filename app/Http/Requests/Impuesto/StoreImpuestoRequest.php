<?php

declare(strict_types=1);

namespace App\Http\Requests\Impuesto;

use App\Models\Tenant\Impuesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImpuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Impuesto::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo'                   => ['required', 'string', Rule::in(Impuesto::TIPOS_VALIDOS)],
            'codigo'                 => ['required', 'string', 'max:20', 'unique:impuestos,codigo'],
            'codigo_dian_ubl'        => ['nullable', 'string', 'max:20'],
            'concepto_dian'          => ['nullable', 'string', 'max:100'],
            'nombre'                 => ['required', 'string', 'max:150'],
            'tarifa_porcentaje'      => ['required', 'numeric', 'min:0', 'max:100'],
            'base_minima_uvt'        => ['nullable', 'numeric', 'min:0'],
            'aplica_compras'         => ['required', 'boolean'],
            'aplica_ventas'          => ['required', 'boolean'],
            'cuenta_contable_id'     => ['required', 'uuid', 'exists:cuentas_contables,id'],
            'cuenta_contrapartida_id'=> ['nullable', 'uuid', 'exists:cuentas_contables,id'],
            'actividad_ciiu'         => ['nullable', 'string', 'max:10'],
            'vigencia_desde'         => ['required', 'date_format:Y-m-d'],
            'vigencia_hasta'         => ['nullable', 'date_format:Y-m-d', 'after:vigencia_desde'],
            'activa'                 => ['nullable', 'boolean'],
            'descripcion'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
