<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Http\Controllers\Api\PaymentConfigurationController;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\PaymentTerm;
use ReflectionMethod;
use Tests\TenantTestCase;

class PaymentConfigurationTest extends TenantTestCase
{
    public function test_default_terms_and_methods_are_created_per_tenant(): void
    {
        $this->assertDatabaseHas('payment_terms', ['code' => 'CONTADO', 'timing' => 'immediate']);
        $this->assertDatabaseHas('payment_terms', ['code' => 'CREDITO', 'timing' => 'credit']);
        $this->assertDatabaseHas('payment_methods', ['code' => 'EFECTIVO', 'type' => 'cash']);
        $this->assertDatabaseHas('payment_methods', ['code' => 'TRANSFERENCIA', 'type' => 'bank']);
        $this->assertSame(2, PaymentTerm::count());
        $this->assertSame(7, PaymentMethod::count());
    }

    public function test_credit_is_not_linked_to_an_immediate_payment_method(): void
    {
        $credit = PaymentTerm::where('code', 'CREDITO')->firstOrFail();
        $cash = PaymentTerm::where('code', 'CONTADO')->firstOrFail();

        $this->assertCount(0, $credit->methods);
        $this->assertCount(7, $cash->methods);
    }

    public function test_controller_declares_tenant_parameter_in_route_order(): void
    {
        $expected = [
            'terms' => ['request', 'tenant'],
            'storeTerm' => ['request', 'tenant'],
            'updateTerm' => ['request', 'tenant', 'id'],
            'termStatus' => ['request', 'tenant', 'id'],
            'methods' => ['request', 'tenant'],
            'storeMethod' => ['request', 'tenant'],
            'updateMethod' => ['request', 'tenant', 'id'],
            'methodStatus' => ['request', 'tenant', 'id'],
            'rules' => ['request', 'tenant'],
            'storeRule' => ['request', 'tenant'],
            'updateRule' => ['request', 'tenant', 'id'],
            'destroyRule' => ['request', 'tenant', 'id'],
        ];

        foreach ($expected as $method => $parameters) {
            $actual = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionMethod(PaymentConfigurationController::class, $method))->getParameters(),
            );
            $this->assertSame($parameters, $actual, $method);
        }
    }
}
