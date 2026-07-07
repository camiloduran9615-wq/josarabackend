<?php

declare(strict_types=1);

namespace App\Http\Requests\Asiento;

use Illuminate\Foundation\Http\FormRequest;

class VoidAsientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('id');
        if (! $id) {
            return false;
        }
        $asiento = \App\Models\Tenant\Asiento::query()->with('periodo')->find((string) $id);
        if (! $asiento) {
            return false;
        }

        return $this->user()?->can('void', $asiento) ?? false;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }
}
