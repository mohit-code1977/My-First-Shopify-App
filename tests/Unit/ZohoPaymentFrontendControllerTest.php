<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Shop;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ZohoConnection;
use App\Models\SyncHistory;
use App\Http\Middleware\ShopifyAuthenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ZohoPaymentFrontendControllerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private ZohoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ShopifyAuthenticate::class]);

        $this->shop = Shop::create([
            'shop_domain' => 'frontend-test-store.myshopify.com',
            'access_token' => 'shpat_test_token_123',
            'scope' => 'read_orders,write_orders',
        ]);

        $this->connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'organization_id' => '123456789',
            'access_token' => '1000.access_token_123',
            'refresh_token' => '1000.refresh_token_123',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_settings_data_returns_tax_and_connection_settings(): void
    {
        $response = $this->actingAsShop()
            ->getJson('/api/zoho/settings');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'shop',
                'zohoConnection',
                'taxSettings',
                'zohoTaxes',
            ]);

        $this->assertArrayNotHasKey('paymentGatewaySettings', $response->json());
    }

    public function test_manual_save_payment_settings_route_is_absent(): void
    {
        $payload = [
            'gateways' => [
                [
                    'shopify_gateway' => 'shopify_payments',
                    'payment_mode' => 'creditcard',
                    'account_id' => 'acc_101',
                ],
            ],
        ];

        $response = $this->actingAsShop()
            ->postJson('/zoho/settings/payment-gateways', $payload);

        $response->assertStatus(404);
    }


    public function test_sync_payment_endpoint_returns_not_found_when_no_payment_or_order(): void
    {
        $response = $this->actingAsShop()
            ->postJson('/zoho/sync-payment', [
                'payment_id' => 99999,
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Payment record not found for sync.',
            ]);
    }

    public function test_history_data_eager_loads_payment_and_searches_by_reference(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '8888',
            'order_number' => '1005',
            'total_price' => 150.00,
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 'gid://shopify/Order/8888',
            'shopify_transaction_id' => 'txn_777',
            'payment_reference' => 'TXN-REF-777',
            'amount' => 150.00,
            'currency' => 'USD',
            'payment_date' => now(),
            'payment_method' => 'shopify_payments',
            'status' => 'paid',
            'sync_status' => 'synced',
            'zoho_payment_id' => 'zp_9999',
        ]);

        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'action' => 'sync_payment',
            'status' => 'synced',
            'zoho_payment_id' => 'zp_9999',
            'message' => 'Payment synchronized successfully.',
            'synced_at' => now(),
        ]);

        $response = $this->actingAsShop()
            ->getJson('/api/zoho/sync/history?search=TXN-REF-777');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $items = $response->json('histories.data');
        $this->assertCount(1, $items);
        $this->assertEquals('zp_9999', $items[0]['zoho_payment_id']);
        $this->assertNotNull($items[0]['payment']);
        $this->assertEquals('TXN-REF-777', $items[0]['payment']['payment_reference']);
    }

    public function test_retry_sync_payment_endpoint_updates_failed_payment_when_customer_and_invoice_are_available(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/7001',
            'zoho_contact_id' => '4081216000000138001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7001',
            'order_number' => '#ORD-7001',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '100.00',
            'total_price' => '100.00',
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => '4081216000000149021',
            'invoice_number' => 'INV-7001',
            'status' => 'sent',
            'amount' => '100.00',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => null,
            'zoho_invoice_id' => null,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/7001',
            'payment_reference' => 'TXN-7001',
            'amount' => 100.00,
            'currency' => 'USD',
            'payment_method' => 'shopify_payments',
            'sync_status' => Payment::SYNC_STATUS_FAILED,
            'error_message' => 'Customer record missing.',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'GET') {
                    return \Illuminate\Support\Facades\Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return \Illuminate\Support\Facades\Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_7001']], 201);
            },
        ]);

        $response = $this->actingAsShop()
            ->postJson('/zoho/sync-payment', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertEquals('4081216000000149021', $response->json('payment.zoho_invoice_id'));
        $this->assertEquals('synced', $response->json('payment.sync_status'));

        $payment->refresh();
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
        $this->assertEquals($invoice->id, $payment->invoice_id);
        $this->assertEquals('4081216000000149021', $payment->zoho_invoice_id);
        $this->assertEquals('zoho_p_7001', $payment->zoho_payment_id);
        $this->assertNull($payment->error_message);
    }

    private function actingAsShop()
    {
        return $this->withHeaders([
            'X-Shop-Domain' => $this->shop->shop_domain,
            'Accept' => 'application/json',
        ])->withUnencryptedCookie('shop_domain', $this->shop->shop_domain);
    }
}
