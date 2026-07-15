<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAccountingRule extends Model
{
    use HasUuids;

    public const ACCOUNT_ROLES = [
        'CASH', 'BANK', 'CUSTOMER_ADVANCES', 'SUPPLIER_ADVANCES',
        'PAYMENT_GATEWAY_RECEIVABLE', 'BANK_FEES', 'FINANCIAL_DISCOUNTS',
        'SURCHARGES', 'ROUNDING_DIFFERENCES', 'CLEARING_ACCOUNT',
    ];

    protected $fillable = [
        'payment_term_id', 'payment_method_id', 'operation_type', 'account_role',
        'accounting_account_id', 'priority', 'effective_from', 'effective_to', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'accounting_account_id');
    }
}
