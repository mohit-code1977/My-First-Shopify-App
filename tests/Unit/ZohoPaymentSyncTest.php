<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoPaymentSyncTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;
    private Order $order;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'pay-sync-test.myshopify.com',
            'access_token' => 'shpat_pay_sync_token',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token_pay',
            'refresh_token' => 'zoho_refresh_token_pay',
            'expires_at' => now()->addHour(),
            'organization_id' => '99887766',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9100',
            'zoho_contact_id' => 'zoho_contact_9100',
            'first_name' => 'Alice',
            'last_name' => 'Payment',
            'email' => 'alice.pay@example.com',
        ]);

        $this->order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/9200',
            'order_number' => '#ORD-9200',
            'zoho_sales_order_id' => 'zoho_so_9200',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '100.00',
            'total_price' => '100.00',
        ]);

        $this->invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_9200',
            'invoice_number' => 'INV-09200',
            'status' => 'sent',
            'amount' => '100.00',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);
    }

    // 1. Shopify Payments -> Zoho creditcard
    public function test_shopify_payments_maps_to_zoho_creditcard()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_sp_100']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/101',
            'payment_reference' => 'TXN-SP-101',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'shopify_payments',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['payment_mode'] === 'creditcard';
        });
    }

    // 2. Stripe -> Zoho creditcard
    public function test_stripe_maps_to_zoho_creditcard()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_stripe_102']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/102',
            'payment_reference' => 'TXN-STRIPE-102',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['payment_mode'] === 'creditcard';
        });
    }

    // 3. PayPal -> Zoho paypal
    public function test_paypal_maps_to_zoho_paypal()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_pp_103']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/103',
            'payment_reference' => 'TXN-PP-103',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'paypal',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['payment_mode'] === 'paypal';
        });
    }

    // 4. COD -> Zoho cash
    public function test_cod_maps_to_zoho_cash()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_cod_104']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/104',
            'payment_reference' => 'TXN-COD-104',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'cash_on_delivery',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['payment_mode'] === 'cash';
        });
    }

    // 5. Bank transfer -> Zoho banktransfer
    public function test_bank_transfer_maps_to_zoho_banktransfer()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_bt_105']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/105',
            'payment_reference' => 'TXN-BT-105',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['payment_mode'] === 'banktransfer';
        });
    }

    // 6. Unknown gateway fails
    public function test_unknown_gateway_fails_without_zoho_post()
    {
        Http::fake();

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/106',
            'payment_reference' => 'TXN-UNK-106',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'unsupported_gateway_xyz',
        ]);

        try {
            $zohoService = new ZohoService($this->shop);
            $zohoService->syncPayment($payment);
            $this->fail("Expected exception was not thrown.");
        } catch (\Throwable $e) {
            $payment->refresh();
            $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
            $this->assertStringContainsString("Unmapped payment gateway 'unsupported_gateway_xyz'", $payment->error_message);
            Http::assertNothingSent();
        }
    }

    // 7. Missing required account ID fails
    public function test_missing_required_account_id_fails()
    {
        config(['services.zoho.payment_gateways.custom_req' => [
            'payment_mode' => 'banktransfer',
            'account_id' => null,
            'require_account_id' => true,
        ]]);

        Http::fake();

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/107',
            'payment_reference' => 'TXN-REQ-ACC-107',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'custom_req',
        ]);

        try {
            $zohoService = new ZohoService($this->shop);
            $zohoService->syncPayment($payment);
            $this->fail("Expected exception for missing account_id was not thrown.");
        } catch (\Throwable $e) {
            $payment->refresh();
            $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
            $this->assertStringContainsString("Payment account ID is required", $payment->error_message);
            Http::assertNothingSent();
        }
    }

    // 8. Correct invoice allocation
    public function test_correct_invoice_allocation_sent()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_alloc_108']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/108',
            'payment_reference' => 'TXN-ALLOC-108',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncPayment($payment);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') return false;
            $invoices = $request->data()['invoices'] ?? [];
            return count($invoices) === 1 &&
                $invoices[0]['invoice_id'] === 'zoho_inv_9200' &&
                $invoices[0]['amount_applied'] === 100.0;
        });
    }

    // 9. Partial payment succeeds
    public function test_partial_payment_succeeds()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_part_109']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/109',
            'payment_reference' => 'TXN-PART-109',
            'amount' => 45.50,
            'currency' => 'USD',
            'payment_method' => 'shopify_payments',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['amount'] === 45.5;
        });
    }

    // 10. Over-allocation fails
    public function test_overallocation_fails_cleanly()
    {
        Http::fake();

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/110',
            'payment_reference' => 'TXN-OVER-110',
            'amount' => 150.00, // Invoice total is 100.00
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ]);

        try {
            $zohoService = new ZohoService($this->shop);
            $zohoService->syncPayment($payment);
            $this->fail("Expected over-allocation exception was not thrown.");
        } catch (\Throwable $e) {
            $payment->refresh();
            $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
            $this->assertStringContainsString("exceeds remaining invoice balance", $payment->error_message);
            Http::assertNothingSent();
        }
    }

    // 11. Correct reference_number sent (clamped to <= 100 chars)
    public function test_correct_reference_number_sent_and_clamped()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_ref_111']], 201);
            },
        ]);

        $longRef = 'LONG-REF-' . str_repeat('X', 120);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/111',
            'payment_reference' => $longRef,
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncPayment($payment);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') return false;
            $sentRef = $request->data()['reference_number'];
            return strlen($sentRef) <= 100 && str_starts_with($sentRef, 'LONG-REF-');
        });
    }

    // 12. Correct payment_mode sent
    public function test_correct_payment_mode_sent()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_mode_112']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/112',
            'payment_reference' => 'TXN-MODE-112',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'manual',
        ]);

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncPayment($payment);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['payment_mode'] === 'others';
        });
    }

    // 13. Correct account_id sent
    public function test_correct_account_id_sent_when_provided()
    {
        config(['services.zoho.payment_gateways.bank_transfer' => [
            'payment_mode' => 'banktransfer',
            'account_id' => '9876543210001',
        ]]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_acc_113']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/113',
            'payment_reference' => 'TXN-ACC-113',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
        ]);

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncPayment($payment);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && $request->data()['account_id'] === '9876543210001';
        });
    }

    // 14. Non-USD currency preserved
    public function test_non_usd_currency_preserved()
    {
        $this->order->update(['currency' => 'EUR']);
        $this->invoice->update(['currency' => 'EUR']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_eur_114']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/114',
            'payment_reference' => 'TXN-EUR-114',
            'amount' => 100.00,
            'currency' => 'EUR',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $this->assertTrue($res['success']);
        $payment->refresh();
        $this->assertEquals('EUR', $payment->currency);
    }

    // 15. Currency mismatch fails
    public function test_currency_mismatch_fails()
    {
        Http::fake();

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id, // Invoice currency is USD
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/115',
            'payment_reference' => 'TXN-MISMATCH-115',
            'amount' => 100.00,
            'currency' => 'EUR',
            'payment_method' => 'stripe',
        ]);

        try {
            $zohoService = new ZohoService($this->shop);
            $zohoService->syncPayment($payment);
            $this->fail("Expected currency mismatch exception was not thrown.");
        } catch (\Throwable $e) {
            $payment->refresh();
            $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
            $this->assertStringContainsString("Currency mismatch", $payment->error_message);
            Http::assertNothingSent();
        }
    }

    // 16. Reference number remains stable across retries
    public function test_reference_number_remains_stable_across_retries()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_stable_116']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/116',
            'payment_reference' => null, // Initially null
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncPayment($payment);

        $payment->refresh();
        $initialRef = $payment->payment_reference;
        $this->assertNotNull($initialRef);

        // Perform second call / retry
        $res2 = $zohoService->syncPayment($payment);
        $payment->refresh();

        $this->assertEquals($initialRef, $payment->payment_reference);
    }

    // 17. Failed Payment retry resolves Invoice and Customer when later available
    public function test_failed_payment_retry_resolves_invoice_and_customer()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/9999',
            'order_number' => '#ORD-9999',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '150.00',
            'total_price' => '150.00',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => null,
            'zoho_invoice_id' => null,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/99991',
            'payment_reference' => 'TXN-RETRY-99991',
            'amount' => 150.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
            'sync_status' => Payment::SYNC_STATUS_FAILED,
            'error_message' => 'Cannot sync payment: Customer record missing.',
        ]);

        // First retry attempt fails because customer is still missing
        try {
            $zohoService = new ZohoService($this->shop);
            $zohoService->syncPayment($payment);
            $this->fail("Expected exception when customer record is missing.");
        } catch (\Throwable $e) {
            $payment->refresh();
            $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
            $this->assertNull($payment->invoice_id);
        }

        // Later: Customer and Invoice become available for the order
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9999',
            'zoho_contact_id' => 'zoho_c_9999',
            'first_name' => 'Retry',
            'last_name' => 'Customer',
            'email' => 'retry.customer@example.com',
        ]);

        $order->update(['customer_id' => $customer->id]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_9999',
            'invoice_number' => 'INV-09999',
            'status' => 'sent',
            'amount' => '150.00',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_retry_9999']], 201);
            },
        ]);

        // Retry syncing the exact same Payment record
        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncPayment($payment);

        // Verify successful retry and linking
        $payment->refresh();
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
        $this->assertEquals($invoice->id, $payment->invoice_id);
        $this->assertEquals('zoho_inv_9999', $payment->zoho_invoice_id);
        $this->assertEquals('zoho_p_retry_9999', $payment->zoho_payment_id);
        $this->assertNull($payment->error_message);
        $this->assertTrue($result['success']);
    }

    public function test_matching_usd_payment_and_usd_invoice_syncs_successfully(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_usd_matching']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/match_usd_1',
            'payment_reference' => 'TXN-MATCH-USD-1',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncPayment($payment);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_pay_usd_matching', $result['zoho_payment_id']);
        $payment->refresh();
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
    }

    public function test_matching_inr_payment_and_inr_invoice_syncs_successfully(): void
    {
        $inrOrder = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/inr_9300',
            'order_number' => '#ORD-INR-9300',
            'zoho_sales_order_id' => 'zoho_so_inr_9300',
            'order_date' => now(),
            'currency' => 'INR',
            'subtotal' => '1845.92',
            'total_price' => '1845.92',
        ]);

        $inrInvoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $inrOrder->id,
            'shopify_order_id' => $inrOrder->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_inr_9300',
            'invoice_number' => 'INV-INR-09300',
            'status' => 'sent',
            'amount' => '1845.92',
            'currency' => 'INR',
            'sync_status' => 'synced',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_pay_inr_matching']], 201);
            },
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $inrOrder->id,
            'invoice_id' => $inrInvoice->id,
            'shopify_order_id' => $inrOrder->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/match_inr_1',
            'payment_reference' => 'TXN-MATCH-INR-1',
            'amount' => 1845.92,
            'currency' => 'INR',
            'payment_method' => 'stripe',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncPayment($payment);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_pay_inr_matching', $result['zoho_payment_id']);
        $payment->refresh();
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
    }

    public function test_inr_payment_and_usd_invoice_mismatch_is_safely_rejected(): void
    {
        Http::fake();

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/mismatch_inr_usd_1',
            'payment_reference' => 'TXN-MISMATCH-INR-USD',
            'amount' => 1845.92,
            'currency' => 'INR',
            'payment_method' => 'stripe',
        ]);

        try {
            $zohoService = new ZohoService($this->shop);
            $zohoService->syncPayment($payment);
            $this->fail("Expected currency mismatch exception was not thrown.");
        } catch (\Throwable $e) {
            $payment->refresh();
            $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
            $this->assertStringContainsString("Currency mismatch (Payment: INR, Invoice: USD)", $payment->error_message);
            Http::assertNothingSent();
        }
    }
}
