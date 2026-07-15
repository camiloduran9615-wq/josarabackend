<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentTerm extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'timing', 'default_credit_days',
        'maximum_installments', 'allows_partial_payment', 'allows_mixed_payment',
        'applies_to_sales', 'applies_to_purchases', 'requires_due_date',
        'is_active', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'default_credit_days' => 'integer',
            'maximum_installments' => 'integer',
            'allows_partial_payment' => 'boolean',
            'allows_mixed_payment' => 'boolean',
            'applies_to_sales' => 'boolean',
            'applies_to_purchases' => 'boolean',
            'requires_due_date' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function methods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'payment_term_methods')
            ->withPivot(['is_default', 'is_active'])->withTimestamps();
    }

    public function accountingRules(): HasMany
    {
        return $this->hasMany(PaymentAccountingRule::class);
    }
}
