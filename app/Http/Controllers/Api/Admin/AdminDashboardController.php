<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $activeSubscriptions = Subscription::with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->get();

        $mrr = $activeSubscriptions->sum(function (Subscription $subscription): float {
            $price = (float) $subscription->price;

            return $subscription->billing_cycle === 'annual' ? $price / 12 : $price;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'tenants_total' => Tenant::count(),
                'tenants_active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
                'tenants_suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
                'tenants_trial' => Tenant::where('status', Tenant::STATUS_TRIAL)->count(),
                'tenants_expired' => Tenant::where('status', Tenant::STATUS_EXPIRED)->count(),
                'new_tenants_this_month' => Tenant::where('created_at', '>=', now()->startOfMonth())->count(),
                'mrr' => round($mrr, 2),
                'arr' => round($mrr * 12, 2),
                'churn' => 0,
                'trial_conversion_rate' => 0,
                'active_users' => null,
                'plans_total' => Plan::count(),
                'plans_active' => Plan::where('status', Plan::STATUS_ACTIVE)->count(),
                'pending_payments' => Subscription::whereIn('payment_status', ['pending', 'failed', 'overdue'])->count(),
                'plans_usage' => Subscription::query()
                    ->select('plan_id', DB::raw('count(*) as total'))
                    ->with('plan')
                    ->groupBy('plan_id')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->get(),
                'critical_alerts' => [],
                'near_limit_tenants' => [],
            ],
        ]);
    }
}
