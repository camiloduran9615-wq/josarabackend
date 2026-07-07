<?php

declare(strict_types=1);

namespace App\Http\Requests\Asiento;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaContable;
use App\Rules\EnPeriodoAbierto;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAsientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asiento::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'fecha'              => ['required', 'date', new EnPeriodoAbierto()],
            'tipo_comprobante'   => ['required', 'string', 'max:4'],
            'descripcion'        => ['required', 'string', 'min:10', 'max:500'],
            'sucursal_id'        => ['nullable', 'uuid'],
            'numero_documento'   => ['nullable', 'string', 'max:50'],
            'soportes_urls'      => ['nullable', 'array', 'max:10'],
            // SEGURIDAD: solo https — bloquear file://, data:, ftp:// (SSRF / XSS latente).
            'soportes_urls.*'    => ['url', 'regex:/^https:\/\//i', 'max:2048'],

            'lineas'              => ['required', 'array', 'min:2'],
            'lineas.*.cuenta_contable_id' => ['required', 'uuid',
                Rule::exists('cuentas_contables', 'id')],
            'lineas.*.tercero_id'        => ['nullable', 'uuid'],
            'lineas.*.centro_costo_id'   => ['nullable', 'uuid'],
            'lineas.*.debito'            => ['required', 'numeric', 'min:0'],
            'lineas.*.credito'           => ['required', 'numeric', 'min:0'],
            'lineas.*.descripcion'       => ['nullable', 'string', 'max:250'],
            'lineas.*.documento_referencia' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var array<int, array<string, mixed>> $lineas */
            $lineas = (array) $this->input('lineas', []);

            $sumD = 0.0;
            $sumC = 0.0;
            foreach ($lineas as $i => $l) {
                $d = (float) ($l['debito'] ?? 0);
                $c = (float) ($l['credito'] ?? 0);
                $sumD += $d;
                $sumC += $c;

                if (($d > 0 && $c > 0) || ($d == 0.0 && $c == 0.0)) {
                    $v->errors()->add(
                        "lineas.{$i}",
                        'Cada línea debe tener exactamente uno positivo: débito o crédito.'
                    );
                }
            }

            if (abs($sumD - $sumC) > 0.01) {
                $diff = round($sumD - $sumC, 4);
                $v->errors()->add(
                    'lineas',
                    "Asiento desbalanceado. ∑D={$sumD}, ∑C={$sumC}, dif={$diff}"
                );
            }

            // Validar tipo_cuenta == 'movimiento' y requiere_tercero
            $cuentaIds = collect($lineas)->pluck('cuenta_contable_id')->filter()->all();
            if ($cuentaIds !== []) {
                $cuentas = CuentaContable::query()
                    ->whereIn('id', $cuentaIds)
                    ->get()
                    ->keyBy('id');

                foreach ($lineas as $i => $l) {
                    $cid = $l['cuenta_contable_id'] ?? null;
                    $cuenta = $cid ? $cuentas->get($cid) : null;
                    if ($cuenta === null) {
                        continue;
                    }
                    if (
                        property_exists($cuenta, 'tipo_cuenta') === false
                        && isset($cuenta->tipo_cuenta) === false
                    ) {
                        // sin columna aún (tenants viejos); no bloqueamos
                    } elseif (($cuenta->tipo_cuenta ?? 'movimiento') === 'agrupacion') {
                        $v->errors()->add(
                            "lineas.{$i}.cuenta_contable_id",
                            "La cuenta {$cuenta->codigo} es de agrupación; no acepta movimientos."
                        );
                    }

                    if (($cuenta->requiere_tercero ?? false) && empty($l['tercero_id'])) {
                        $v->errors()->add(
                            "lineas.{$i}.tercero_id",
                            "La cuenta {$cuenta->codigo} requiere tercero_id."
                        );
                    }
                }
            }
        });
    }
}
