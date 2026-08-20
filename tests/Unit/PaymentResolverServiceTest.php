<?php

namespace Tests\Unit;

use App\Services\PaymentResolverService;
use Tests\TestCase;

class PaymentResolverServiceTest extends TestCase
{
    private PaymentResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PaymentResolverService();
    }

    public function test_resolves_shopify_payments_to_creditcard(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'shopify_payments']);
        $this->assertEquals('creditcard', $res['payment_mode']);
        $this->assertNull($res['account_id']);
        $this->assertEquals('automatic', $res['source']);
        $this->assertEquals('Shopify Payments', $res['gateway_label']);
    }

    public function test_resolves_stripe_to_creditcard(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'stripe']);
        $this->assertEquals('creditcard', $res['payment_mode']);
        $this->assertNull($res['account_id']);
    }

    public function test_resolves_paypal_to_paypal(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'paypal']);
        $this->assertEquals('paypal', $res['payment_mode']);
        $this->assertNull($res['account_id']);
    }

    public function test_resolves_cod_to_cash(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'cash_on_delivery']);
        $this->assertEquals('cash', $res['payment_mode']);

        $res2 = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'cod']);
        $this->assertEquals('cash', $res2['payment_mode']);
    }

    public function test_resolves_bank_transfer_to_banktransfer(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'bank_transfer']);
        $this->assertEquals('banktransfer', $res['payment_mode']);

        $res2 = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'wire']);
        $this->assertEquals('banktransfer', $res2['payment_mode']);
    }

    public function test_resolves_manual_to_others(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'manual']);
        $this->assertEquals('others', $res['payment_mode']);
    }

    public function test_resolves_unknown_gateway_safely_to_others_fallback(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => 'unknown_crypto_gateway_xyz']);
        $this->assertEquals('others', $res['payment_mode']);
        $this->assertNull($res['account_id']);
        $this->assertEquals('automatic', $res['source']);
    }

    public function test_resolves_missing_empty_gateway_safely_to_creditcard_default(): void
    {
        $res = $this->resolver->resolveZohoPaymentDetails(['gateway' => '']);
        $this->assertEquals('creditcard', $res['payment_mode']);
        $this->assertNull($res['account_id']);

        $resNull = $this->resolver->resolveZohoPaymentDetails([]);
        $this->assertEquals('creditcard', $resNull['payment_mode']);
    }

    public function test_transaction_kind_helpers(): void
    {
        $this->assertTrue($this->resolver->isPaymentCapture('sale'));
        $this->assertTrue($this->resolver->isPaymentCapture('capture'));
        $this->assertFalse($this->resolver->isPaymentCapture('authorization'));

        $this->assertTrue($this->resolver->isRefund('refund'));
        $this->assertTrue($this->resolver->isVoid('void'));
        $this->assertTrue($this->resolver->isAuthorization('authorization'));
    }

    public function test_get_known_gateways_returns_non_empty_list(): void
    {
        $known = $this->resolver->getKnownGateways();
        $this->assertIsArray($known);
        $this->assertNotEmpty($known);
    }
}
