<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Tenant\PaymentAccountingRule;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\PaymentTerm;
use Illuminate\Validation\ValidationException;

class PaymentSelectionService
{
    /** @return array{term: PaymentTerm|null, method: PaymentMethod|null, legacy_form: string} */
    public function forPurchase(?string $termId, ?string $methodId, ?string $legacyForm): array
    {
        if ($termId === null && $methodId === null) {
            return ['term' => null, 'method' => null, 'legacy_form' => $legacyForm ?? 'contado'];
        }

        $term = $this->activeTerm($termId, 'purchase');
        if ($term->timing === 'credit') {
            $method = $methodId ? $this->activeMethod($methodId, 'purchase') : null;
            $this->assertAllowedCombination($term, $method);
            return ['term' => $term, 'method' => $method, 'legacy_form' => 'credito'];
        }

        if ($methodId === null) {
            throw ValidationException::withMessages(['payment_method_id' => 'El medio de pago es obligatorio para una compra de contado.']);
        }
        $method = $this->activeMethod($methodId, 'purchase');
        $this->assertAllowedCombination($term, $method);

        $legacy = match ($method->type) {
            'cash' => 'contado_efectivo',
            'bank', 'card', 'check' => 'contado_banco',
            default => $this->legacyFromAccountingRule($term, $method),
        };

        return ['term' => $term, 'method' => $method, 'legacy_form' => $legacy];
    }

    /** @return array{term: PaymentTerm|null, method: PaymentMethod|null, payment_form: string, payment_method_code: string} */
    public function forSale(?string $termId, ?string $methodId, ?string $legacyForm, ?string $legacyMethod): array
    {
        if ($termId === null && $methodId === null) {
            return [
                'term' => null, 'method' => null,
                'payment_form' => $legacyForm ?? '1',
                'payment_method_code' => $legacyMethod ?? '10',
            ];
        }

        $term = $this->activeTerm($termId, 'sale');
        if ($methodId === null) {
            throw ValidationException::withMessages(['payment_method_id' => 'El medio de pago es obligatorio para la factura electrónica.']);
        }
        $method = $this->activeMethod($methodId, 'sale');
        $this->assertAllowedCombination($term, $method);
        if (! $method->dian_code) {
            throw ValidationException::withMessages(['payment_method_id' => 'El medio seleccionado no tiene código DIAN configurado.']);
        }

        return [
            'term' => $term, 'method' => $method,
            'payment_form' => $term->timing === 'credit' ? '2' : '1',
            'payment_method_code' => $method->dian_code,
        ];
    }

    private function activeTerm(?string $id, string $operation): PaymentTerm
    {
        if (! $id) {
            throw ValidationException::withMessages(['payment_term_id' => 'La condición de pago es obligatoria.']);
        }
        $column = $operation === 'purchase' ? 'applies_to_purchases' : 'applies_to_sales';
        $term = PaymentTerm::query()->whereKey($id)->where('is_active', true)->where($column, true)->first();
        if (! $term) {
            throw ValidationException::withMessages(['payment_term_id' => 'La condición de pago no está activa o no aplica a esta operación.']);
        }
        return $term;
    }

    private function activeMethod(string $id, string $operation): PaymentMethod
    {
        $column = $operation === 'purchase' ? 'allows_purchases' : 'allows_sales';
        $method = PaymentMethod::query()->whereKey($id)->where('is_active', true)->where($column, true)->first();
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => 'El medio de pago no está activo o no aplica a esta operación.']);
        }
        return $method;
    }

    private function assertAllowedCombination(PaymentTerm $term, ?PaymentMethod $method): void
    {
        if (! $method || ! $term->methods()->wherePivot('is_active', true)->exists()) return;
        if (! $term->methods()->whereKey($method->id)->wherePivot('is_active', true)->exists()) {
            throw ValidationException::withMessages(['payment_method_id' => 'El medio de pago no está permitido para la condición seleccionada.']);
        }
    }

    private function legacyFromAccountingRule(PaymentTerm $term, PaymentMethod $method): string
    {
        $roles = PaymentAccountingRule::query()
            ->where('operation_type', 'purchase')->where('is_active', true)
            ->where(fn ($q) => $q->where('payment_method_id', $method->id)->orWhere('payment_term_id', $term->id))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
            ->orderBy('priority')->pluck('account_role');

        if ($roles->contains('CASH')) return 'contado_efectivo';
        if ($roles->intersect(['BANK', 'CLEARING_ACCOUNT'])->isNotEmpty()) return 'contado_banco';
        throw ValidationException::withMessages(['payment_method_id' => 'El medio requiere una regla contable de caja, banco o cuenta puente antes de utilizarse.']);
    }
}
