<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'type', 'dian_code',
        'requires_cash_account', 'requires_bank_account', 'requires_reference',
        'allows_sales', 'allows_purchases', 'is_active', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_cash_account' => 'boolean',
            'requires_bank_account' => 'boolean',
            'requires_reference' => 'boolean',
            'allows_sales' => 'boolean',
            'allows_purchases' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(PaymentTerm::class, 'payment_term_methods')
            ->withPivot(['is_default', 'is_active'])->withTimestamps();
    }

    public function accountingRules(): HasMany
    {
        return $this->hasMany(PaymentAccountingRule::class);
    }
}
