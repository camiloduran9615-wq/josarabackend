<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\PaymentTerm;
use App\Services\Payments\PaymentSelectionService;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

class PaymentSelectionServiceTest extends TenantTestCase
{
    private PaymentSelectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(PaymentSelectionService::class);
    }

    public function test_purchase_cash_and_bank_are_derived_server_side(): void
    {
        $term = PaymentTerm::where('code', 'CONTADO')->firstOrFail();
        $cash = PaymentMethod::where('code', 'EFECTIVO')->firstOrFail();
        $bank = PaymentMethod::where('code', 'TRANSFERENCIA')->firstOrFail();

        $this->assertSame('contado_efectivo', $this->service->forPurchase($term->id, $cash->id, null)['legacy_form']);
        $this->assertSame('contado_banco', $this->service->forPurchase($term->id, $bank->id, null)['legacy_form']);
    }

    public function test_credit_purchase_is_derived_from_term_and_does_not_require_method(): void
    {
        $term = PaymentTerm::where('code', 'CREDITO')->firstOrFail();
        $result = $this->service->forPurchase($term->id, null, 'contado_efectivo');

        $this->assertSame('credito', $result['legacy_form']);
        $this->assertNull($result['method']);
    }

    public function test_sale_uses_configured_dian_mapping(): void
    {
        $term = PaymentTerm::where('code', 'CONTADO')->firstOrFail();
        $method = PaymentMethod::where('code', 'TRANSFERENCIA')->firstOrFail();
        $result = $this->service->forSale($term->id, $method->id, '2', '10');

        $this->assertSame('1', $result['payment_form']);
        $this->assertSame('42', $result['payment_method_code']);
    }

    public function test_inactive_method_cannot_be_used(): void
    {
        $term = PaymentTerm::where('code', 'CONTADO')->firstOrFail();
        $method = PaymentMethod::where('code', 'EFECTIVO')->firstOrFail();
        $method->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->service->forPurchase($term->id, $method->id, null);
    }

    public function test_legacy_clients_remain_compatible(): void
    {
        $purchase = $this->service->forPurchase(null, null, 'contado_banco');
        $sale = $this->service->forSale(null, null, '2', '48');

        $this->assertSame('contado_banco', $purchase['legacy_form']);
        $this->assertSame('2', $sale['payment_form']);
        $this->assertSame('48', $sale['payment_method_code']);
    }
}
