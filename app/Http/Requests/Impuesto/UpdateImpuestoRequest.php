<?php

declare(strict_types=1);

namespace App\Http\Requests\Impuesto;

use App\Models\Tenant\Impuesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateImpuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Impuesto|null $impuesto */
        $impuesto = $this->route('impuesto');

        return $impuesto !== null
            && ($this->user()?->can('update', $impuesto) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('impuesto') instanceof Impuesto
            ? $this->route('impuesto')->id
            : (string) $this->route('impuesto');

        return [
            'codigo'                 => ['sometimes', 'string', 'max:20', Rule::unique('impuestos', 'codigo')->ignore($id)],
            'codigo_dian_ubl'        => ['nullable', 'string', 'max:20'],
            'concepto_dian'          => ['nullable', 'string', 'max:100'],
            'nombre'                 => ['sometimes', 'string', 'max:150'],
            'tarifa_porcentaje'      => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'base_minima_uvt'        => ['nullable', 'numeric', 'min:0'],
            'aplica_compras'         => ['sometimes', 'boolean'],
            'aplica_ventas'          => ['sometimes', 'boolean'],
            'cuenta_contable_id'     => ['sometimes', 'uuid', 'exists:cuentas_contables,id'],
            'cuenta_contrapartida_id'=> ['nullable', 'uuid', 'exists:cuentas_contables,id'],
            'actividad_ciiu'         => ['nullable', 'string', 'max:10'],
            'vigencia_desde'         => ['sometimes', 'date_format:Y-m-d'],
            'vigencia_hasta'         => ['nullable', 'date_format:Y-m-d', 'after:vigencia_desde'],
            'activa'                 => ['sometimes', 'boolean'],
            'descripcion'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
