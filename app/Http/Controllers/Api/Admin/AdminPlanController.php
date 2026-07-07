<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlatformAdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminPlanController extends Controller
{
    public function __construct(private readonly PlatformAdminAuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Plan::withCount('subscriptions')->with('features');
        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $plans = $query->orderBy('display_order')
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json(['success' => true, 'data' => $plans]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMutation($request);

        $validated = $this->validatePlan($request);

        $plan = DB::transaction(function () use ($validated): Plan {
            $features = $validated['features'] ?? [];
            unset($validated['features']);

            $plan = Plan::create($validated);
            $this->syncFeatures($plan, $features);

            return $plan->load('features');
        });

        $this->audit->log($request, 'plan.created', Plan::class, $plan->id, ['code' => $plan->code]);

        return response()->json(['success' => true, 'data' => $plan], Response::HTTP_CREATED);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $plan->load(['features', 'subscriptions.tenant']),
        ]);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $this->authorizeMutation($request);

        $validated = $this->validatePlan($request, $plan);

        DB::transaction(function () use ($plan, $validated): void {
            $features = $validated['features'] ?? null;
            unset($validated['features']);

            $plan->update($validated);

            if (is_array($features)) {
                $this->syncFeatures($plan, $features);
            }
        });

        $this->audit->log($request, 'plan.updated', Plan::class, $plan->id, ['code' => $plan->code]);

        return response()->json(['success' => true, 'data' => $plan->fresh('features')]);
    }

    public function duplicate(Request $request, Plan $plan): JsonResponse
    {
        $this->authorizeMutation($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'unique:plans,code'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $copy = DB::transaction(function () use ($plan, $validated): Plan {
            $copy = $plan->replicate(['code']);
            $copy->code = mb_strtolower($validated['code']);
            $copy->name = $validated['name'] ?? $plan->name.' copia';
            $copy->status = Plan::STATUS_INACTIVE;
            $copy->save();

            foreach ($plan->features as $feature) {
                $copy->features()->create($feature->only([
                    'feature_key',
                    'feature_label',
                    'limit_value',
                    'enabled',
                    'metadata',
                ]));
            }

            return $copy->load('features');
        });

        $this->audit->log($request, 'plan.duplicated', Plan::class, $copy->id, [
            'source_plan_id' => $plan->id,
        ]);

        return response()->json(['success' => true, 'data' => $copy], Response::HTTP_CREATED);
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('plans', 'code')->ignore($plan?->id),
            ],
            'description' => ['nullable', 'string'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'annual_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['required', Rule::in([Plan::STATUS_ACTIVE, Plan::STATUS_INACTIVE])],
            'is_recommended' => ['sometimes', 'boolean'],
            'is_free' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'trial_allowed' => ['sometimes', 'boolean'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'features' => ['sometimes', 'array'],
            'features.*.feature_key' => ['required_with:features', 'string', 'max:100'],
            'features.*.feature_label' => ['nullable', 'string', 'max:255'],
            'features.*.limit_value' => ['nullable', 'integer', 'min:0'],
            'features.*.enabled' => ['sometimes', 'boolean'],
            'features.*.metadata' => ['nullable', 'array'],
        ]);
    }

    private function syncFeatures(Plan $plan, array $features): void
    {
        $seen = [];

        foreach ($features as $feature) {
            $seen[] = $feature['feature_key'];
            $plan->features()->updateOrCreate(
                ['feature_key' => $feature['feature_key']],
                [
                    'feature_label' => $feature['feature_label'] ?? null,
                    'limit_value' => $feature['limit_value'] ?? null,
                    'enabled' => $feature['enabled'] ?? true,
                    'metadata' => $feature['metadata'] ?? null,
                ],
            );
        }

        if ($seen !== []) {
            $plan->features()->whereNotIn('feature_key', $seen)->delete();
        }
    }

    private function authorizeMutation(Request $request): void
    {
        /** @var mixed $user */
        $user = $request->user();

        if (! $user instanceof \App\Models\PlatformAdmin || ! $user->canManagePlatform()) {
            abort(Response::HTTP_FORBIDDEN, 'No autorizado.');
        }
    }
}
