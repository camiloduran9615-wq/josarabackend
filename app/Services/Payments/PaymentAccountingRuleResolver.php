<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\PaymentAccountingRule;
use Carbon\CarbonInterface;

class PaymentAccountingRuleResolver
{
    public function resolve(
        string $operation,
        string $role,
        ?string $termId,
        ?string $methodId,
        CarbonInterface|string|null $date = null,
    ): ?CuentaContable {
        $date = $date ? (string) $date : today()->toDateString();

        $rule = PaymentAccountingRule::query()
            ->with('account')
            ->where('operation_type', $operation)
            ->where('account_role', $role)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where(function ($q) use ($termId, $methodId): void {
                $q->where(function ($exact) use ($termId, $methodId): void {
                    $exact->where('payment_term_id', $termId)->where('payment_method_id', $methodId);
                })->orWhere(function ($method) use ($methodId): void {
                    $method->whereNull('payment_term_id')->where('payment_method_id', $methodId);
                })->orWhere(function ($term) use ($termId): void {
                    $term->where('payment_term_id', $termId)->whereNull('payment_method_id');
                });
            })
            ->orderByRaw('CASE WHEN payment_term_id IS NOT NULL AND payment_method_id IS NOT NULL THEN 0 WHEN payment_method_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('priority')
            ->first();

        $account = $rule?->account;
        return $account && $account->activo && $account->acepta_movimientos ? $account : null;
    }
}
