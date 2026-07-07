<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public const STATUS_ACTIVE = 'activa';
    public const STATUS_TRIAL = 'en_trial';
    public const STATUS_SUSPENDED = 'suspendida';
    public const STATUS_EXPIRED = 'vencida';
    public const STATUS_CANCELLED = 'cancelada';
    public const STATUS_BLOCKED = 'bloqueada';
    public const STATUS_PAYMENT_PENDING = 'pendiente_pago';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_TRIAL,
        self::STATUS_SUSPENDED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_BLOCKED,
        self::STATUS_PAYMENT_PENDING,
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $source = (string) ($tenant->tenant_slug ?: $tenant->company_code ?: $tenant->razon_social ?: $tenant->id);
            $tenant->tenant_slug = self::generateUniqueTenantSlug($source);

            if (! is_string($tenant->company_code) || $tenant->company_code === '') {
                $tenant->company_code = $tenant->tenant_slug;
            }
        });
    }

    protected $fillable = [
        'id',
        'tenant_slug',
        'company_code',
        'razon_social',
        'nit',
        'email_contacto',
        'telefono',
        'direccion',
        'ciudad',
        'country',
        'plan_id',
        'trial_ends_at',
        'last_access_at',
        'storage_bytes_used',
        'activo',
        'status',
        'billing_status',
        'electronic_invoicing_status',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'tenant_slug',
            'company_code',
            'razon_social',
            'nit',
            'email_contacto',
            'telefono',
            'direccion',
            'ciudad',
            'country',
            'plan_id',
            'trial_ends_at',
            'last_access_at',
            'storage_bytes_used',
            'activo',
            'status',
            'billing_status',
            'electronic_invoicing_status',
        ];
    }

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'trial_ends_at' => 'datetime',
            'last_access_at' => 'datetime',
            'storage_bytes_used' => 'integer',
        ];
    }

    public static function normalizeTenantSlug(string $value): string
    {
        return Str::slug(trim($value));
    }

    public static function normalizeCompanyCode(string $value): string
    {
        return self::normalizeTenantSlug($value);
    }

    public static function generateUniqueTenantSlug(string $name, ?string $ignoreTenantId = null): string
    {
        $base = self::normalizeTenantSlug($name);
        $base = trim(substr($base !== '' ? $base : 'empresa', 0, 48), '-');
        $candidate = $base;
        $counter = 2;

        while (self::query()
            ->where(function ($query) use ($candidate): void {
                $query->where('tenant_slug', $candidate)
                    ->orWhere('company_code', $candidate);
            })
            ->when($ignoreTenantId !== null, fn ($query) => $query->whereKeyNot($ignoreTenantId))
            ->exists()
        ) {
            $suffix = '-'.$counter;
            $candidate = substr($base, 0, 80 - strlen($suffix)).$suffix;
            $counter++;
        }

        return $candidate;
    }

    public static function generateUniqueCompanyCode(string $name, ?string $ignoreTenantId = null): string
    {
        return self::generateUniqueTenantSlug($name, $ignoreTenantId);
    }

    public static function resolveByPublicIdentifier(?string $identifier): ?self
    {
        $value = trim((string) $identifier);

        if ($value === '') {
            return null;
        }

        $normalized = self::normalizeTenantSlug($value);

        return self::query()
            ->whereKey($value)
            ->orWhere('tenant_slug', $value)
            ->orWhere('tenant_slug', $normalized)
            ->orWhere('company_code', $value)
            ->orWhere('company_code', $normalized)
            ->first();
    }

    public function publicIdentifier(): string
    {
        return (string) ($this->tenant_slug ?: $this->company_code ?: $this->id);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(TenantUsageSnapshot::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TenantStatusHistory::class);
    }
}
