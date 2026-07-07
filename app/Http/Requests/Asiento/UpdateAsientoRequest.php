<?php

declare(strict_types=1);

namespace App\Http\Requests\Asiento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reutiliza la validación de Store; el controller pasa esta request al service.
 * El authorize lo evalúa el controller con la Policy update().
 */
class UpdateAsientoRequest extends StoreAsientoRequest
{
    public function authorize(): bool
    {
        $id = $this->route('id');
        if (! $id) {
            return false;
        }
        $asiento = \App\Models\Tenant\Asiento::query()->find((string) $id);
        if (! $asiento) {
            return false; // resultará en 404 vía findOrFail en el controller
        }

        return $this->user()?->can('update', $asiento) ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        // En update, las líneas son opcionales (puedes editar solo cabecera)
        $rules['lineas'] = ['sometimes', 'array', 'min:2'];
        return $rules;
    }
}
