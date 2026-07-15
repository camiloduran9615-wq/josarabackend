<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PaymentAccountingRule;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\PaymentTerm;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentConfigurationController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function terms(Request $request, string $tenant): JsonResponse
    {
        $query = PaymentTerm::query()->with(['methods' => fn ($q) => $q->orderBy('display_order')]);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        return response()->json(['success' => true, 'data' => $query->orderBy('display_order')->orderBy('name')->get()]);
    }

    public function storeTerm(Request $request, string $tenant): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $validated = $this->validateTerm($request);
        $methodIds = $validated['method_ids'] ?? [];
        unset($validated['method_ids']);
        $validated['code'] = strtoupper($validated['code']);
        $term = DB::transaction(function () use ($validated, $methodIds): PaymentTerm {
            $term = PaymentTerm::create($validated);
            $this->syncMethods($term, $methodIds);
            return $term;
        });
        $this->audit('payment_term.created', $term, null, $term->toArray());
        return response()->json(['success' => true, 'data' => $term->load('methods')], 201);
    }

    public function updateTerm(Request $request, string $tenant, string $id): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $term = PaymentTerm::findOrFail($id);
        $validated = $this->validateTerm($request, $term);
        $old = $term->toArray();
        $methodIds = $validated['method_ids'] ?? null;
        unset($validated['method_ids']);
        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        DB::transaction(function () use ($term, $validated, $methodIds): void {
            $term->update($validated);
            if ($methodIds !== null) {
                $this->syncMethods($term, $methodIds);
            }
        });
        $this->audit('payment_term.updated', $term, $old, $term->fresh()->toArray());
        return response()->json(['success' => true, 'data' => $term->fresh()->load('methods')]);
    }

    public function termStatus(Request $request, string $tenant, string $id): JsonResponse
    {
        return $this->changeStatus($request, PaymentTerm::findOrFail($id), 'payment_term');
    }

    public function methods(Request $request, string $tenant): JsonResponse
    {
        $query = PaymentMethod::query();
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->operation === 'purchase') {
            $query->where('allows_purchases', true);
        } elseif ($request->operation === 'sale') {
            $query->where('allows_sales', true);
        }
        return response()->json(['success' => true, 'data' => $query->orderBy('display_order')->orderBy('name')->get()]);
    }

    public function storeMethod(Request $request, string $tenant): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $validated = $this->validateMethod($request);
        $validated['code'] = strtoupper($validated['code']);
        $method = PaymentMethod::create($validated);
        $this->audit('payment_method.created', $method, null, $method->toArray());
        return response()->json(['success' => true, 'data' => $method], 201);
    }

    public function updateMethod(Request $request, string $tenant, string $id): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $method = PaymentMethod::findOrFail($id);
        $validated = $this->validateMethod($request, $method);
        $old = $method->toArray();
        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        $method->update($validated);
        $this->audit('payment_method.updated', $method, $old, $method->fresh()->toArray());
        return response()->json(['success' => true, 'data' => $method->fresh()]);
    }

    public function methodStatus(Request $request, string $tenant, string $id): JsonResponse
    {
        return $this->changeStatus($request, PaymentMethod::findOrFail($id), 'payment_method');
    }

    public function rules(Request $request, string $tenant): JsonResponse
    {
        $rules = PaymentAccountingRule::with(['term:id,code,name', 'method:id,code,name', 'account:id,codigo,nombre,activo,acepta_movimientos'])
            ->when($request->operation_type, fn ($q, $value) => $q->where('operation_type', $value))
            ->orderBy('operation_type')->orderBy('priority')->get();
        return response()->json(['success' => true, 'data' => $rules]);
    }

    public function storeRule(Request $request, string $tenant): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $validated = $this->validateRule($request);
        $this->assertNoOverlappingRule($validated);
        $rule = PaymentAccountingRule::create($validated);
        $this->audit('payment_accounting_rule.created', $rule, null, $rule->toArray());
        return response()->json(['success' => true, 'data' => $rule->load('term', 'method', 'account')], 201);
    }

    public function updateRule(Request $request, string $tenant, string $id): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $rule = PaymentAccountingRule::findOrFail($id);
        $validated = $this->validateRule($request, true);
        $candidate = array_merge($rule->only($rule->getFillable()), $validated);
        $this->assertNoOverlappingRule($candidate, $rule->id);
        $old = $rule->toArray();
        $rule->update($validated);
        $this->audit('payment_accounting_rule.updated', $rule, $old, $rule->fresh()->toArray());
        return response()->json(['success' => true, 'data' => $rule->fresh()->load('term', 'method', 'account')]);
    }

    public function destroyRule(Request $request, string $tenant, string $id): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $rule = PaymentAccountingRule::findOrFail($id);
        $old = $rule->toArray();
        $rule->update(['is_active' => false]);
        $this->audit('payment_accounting_rule.deactivated', $rule, $old, $rule->fresh()->toArray());
        return response()->json(['success' => true, 'data' => $rule->fresh()]);
    }

    private function validateTerm(Request $request, ?PaymentTerm $term = null): array
    {
        return $request->validate([
            'code' => [$term ? 'sometimes' : 'required', 'string', 'max:30', Rule::unique('payment_terms', 'code')->ignore($term?->id)],
            'name' => [$term ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'timing' => [$term ? 'sometimes' : 'required', Rule::in(['immediate', 'credit'])],
            'default_credit_days' => ['integer', 'min:0', 'max:3650'],
            'maximum_installments' => ['integer', 'min:1', 'max:120'],
            'allows_partial_payment' => ['boolean'], 'allows_mixed_payment' => ['boolean'],
            'applies_to_sales' => ['boolean'], 'applies_to_purchases' => ['boolean'],
            'requires_due_date' => ['boolean'], 'is_active' => ['boolean'],
            'display_order' => ['integer', 'min:0', 'max:65535'],
            'method_ids' => ['sometimes', 'array'],
            'method_ids.*' => ['uuid', 'distinct', 'exists:payment_methods,id'],
        ]);
    }

    private function validateMethod(Request $request, ?PaymentMethod $method = null): array
    {
        return $request->validate([
            'code' => [$method ? 'sometimes' : 'required', 'string', 'max:30', Rule::unique('payment_methods', 'code')->ignore($method?->id)],
            'name' => [$method ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => [$method ? 'sometimes' : 'required', Rule::in(['cash', 'bank', 'card', 'check', 'credit', 'advance', 'compensation', 'other'])],
            'dian_code' => ['nullable', 'string', 'max:5'],
            'requires_cash_account' => ['boolean'], 'requires_bank_account' => ['boolean'],
            'requires_reference' => ['boolean'], 'allows_sales' => ['boolean'],
            'allows_purchases' => ['boolean'], 'is_active' => ['boolean'],
            'display_order' => ['integer', 'min:0', 'max:65535'],
        ]);
    }

    private function validateRule(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'payment_term_id' => ['nullable', 'uuid', 'exists:payment_terms,id', 'required_without:payment_method_id'],
            'payment_method_id' => ['nullable', 'uuid', 'exists:payment_methods,id', 'required_without:payment_term_id'],
            'operation_type' => [$partial ? 'sometimes' : 'required', Rule::in(['sale', 'purchase'])],
            'account_role' => [$partial ? 'sometimes' : 'required', Rule::in(PaymentAccountingRule::ACCOUNT_ROLES)],
            'accounting_account_id' => [$partial ? 'sometimes' : 'required', 'uuid', Rule::exists('cuentas_contables', 'id')->where(fn ($q) => $q->where('activo', true)->where('acepta_movimientos', true))],
            'priority' => ['integer', 'min:1', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['boolean'],
        ]);
    }

    private function assertNoOverlappingRule(array $data, ?string $ignoreId = null): void
    {
        if (! ($data['is_active'] ?? true)) return;
        $query = PaymentAccountingRule::query()->where('operation_type', $data['operation_type'])
            ->where('account_role', $data['account_role'])->where('priority', $data['priority'] ?? 100)->where('is_active', true);
        isset($data['payment_term_id']) ? $query->where('payment_term_id', $data['payment_term_id']) : $query->whereNull('payment_term_id');
        isset($data['payment_method_id']) ? $query->where('payment_method_id', $data['payment_method_id']) : $query->whereNull('payment_method_id');
        if ($ignoreId) $query->whereKeyNot($ignoreId);
        $from = $data['effective_from'] ?? '0001-01-01';
        $to = $data['effective_to'] ?? '9999-12-31';
        $query->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to));
        if ($query->exists()) {
            throw ValidationException::withMessages(['priority' => 'Ya existe una regla activa con el mismo contexto, prioridad y vigencia.']);
        }
    }

    private function syncMethods(PaymentTerm $term, array $methodIds): void
    {
        $term->methods()->sync(collect($methodIds)->mapWithKeys(fn ($id) => [$id => ['is_active' => true, 'is_default' => false]])->all());
    }

    private function changeStatus(Request $request, Model $model, string $auditPrefix): JsonResponse
    {
        $this->authorizeConfiguration($request);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $old = $model->toArray();
        $model->update($validated);
        $this->audit($auditPrefix.'.'.($validated['is_active'] ? 'activated' : 'deactivated'), $model, $old, $model->fresh()->toArray());
        return response()->json(['success' => true, 'data' => $model->fresh()]);
    }

    private function authorizeConfiguration(Request $request): void
    {
        if (! in_array($request->user()?->role, [User::ROLE_ADMIN, User::ROLE_CONTADOR], true)) {
            abort(403, 'Solo administradores y contadores pueden modificar la configuración de pagos.');
        }
    }

    private function audit(string $action, Model $model, ?array $old, array $new): void
    {
        $this->auditLog->record(action: $action, auditable: $model, oldValues: $old, newValues: $new);
    }
}
