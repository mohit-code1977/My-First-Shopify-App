<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoCustomerSyncTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'customer-test.myshopify.com',
            'access_token' => 'shpat_test_token',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token',
            'refresh_token' => 'zoho_refresh_token',
            'expires_at' => now()->addHour(),
            'organization_id' => '12345678',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);
    }

    private function calculateHmac(string $payload, string $secret): string {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    public function test_customer_creation_in_zoho_books() {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/1001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '+15551234567',
            'billing_address' => [
                'address1' => '123 Main St',
                'city' => 'New York',
                'province' => 'NY',
                'zip' => '10001',
                'country' => 'USA',
            ],
            'shipping_address' => [
                'address1' => '123 Main St',
                'city' => 'New York',
                'province' => 'NY',
                'zip' => '10001',
                'country' => 'USA',
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'contacts' => [],
                    ], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Contact has been added',
                        'contact' => [
                            'contact_id' => 'zoho_contact_1001',
                            'contact_name' => 'John Doe',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncCustomer($customer);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created']);
        $this->assertEquals('zoho_contact_1001', $result['zoho_contact_id']);
        $this->assertEquals('zoho_contact_1001', $customer->fresh()->zoho_contact_id);

        $this->assertDatabaseHas('sync_histories', [
            'shop_id' => $this->shop->id,
            'zoho_item_id' => 'zoho_contact_1001',
            'status' => 'success',
            'action' => 'created',
        ]);
    }

    public function test_existing_customer_detection_and_mapping_by_email() {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/1002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'contacts' => [
                            [
                                'contact_id' => 'zoho_contact_existing_55',
                                'contact_name' => 'Jane Smith',
                                'email' => 'jane.smith@example.com',
                            ],
                        ],
                    ], 200);
                }
                if ($request->method() === 'PUT') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Contact has been updated',
                        'contact' => [
                            'contact_id' => 'zoho_contact_existing_55',
                        ],
                    ], 200);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncCustomer($customer);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['updated']);
        $this->assertEquals('zoho_contact_existing_55', $customer->fresh()->zoho_contact_id);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' &&
                str_contains($request->url(), '/books/v3/contacts/zoho_contact_existing_55');
        });
    }

    public function test_customer_update_in_zoho_books() {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/1003',
            'zoho_contact_id' => 'zoho_contact_2002',
            'first_name' => 'Robert',
            'last_name' => 'Johnson',
            'email' => 'robert@example.com',
            'phone' => '+15559876543',
            'billing_address' => [
                'address1' => '456 Market St',
                'city' => 'San Francisco',
                'province' => 'CA',
                'zip' => '94105',
                'country' => 'USA',
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => function (Request $request) {
                if ($request->method() === 'PUT') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Contact updated successfully',
                        'contact' => [
                            'contact_id' => 'zoho_contact_2002',
                        ],
                    ], 200);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncCustomer($customer);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['updated']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' &&
                str_contains($request->url(), '/books/v3/contacts/zoho_contact_2002') &&
                $request->data()['contact_name'] === 'Robert Johnson' &&
                $request->data()['billing_address']['city'] === 'San Francisco';
        });
    }

    public function test_customer_sync_handles_missing_optional_fields() {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/1004',
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'phone' => null,
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'contacts' => [],
                    ], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Contact has been added',
                        'contact' => [
                            'contact_id' => 'zoho_contact_minimal_1004',
                            'contact_name' => 'Shopify Customer',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncCustomer($customer);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_contact_minimal_1004', $customer->fresh()->zoho_contact_id);
    }

    public function test_customer_webhook_creates_and_syncs_customer() {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'contacts' => [],
                    ], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'contact' => [
                            'contact_id' => 'zoho_contact_alice',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $payload = json_encode([
            'id' => 9001,
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
            'email' => 'alice@example.com',
            'phone' => '+15550001111',
            'default_address' => [
                'address1' => '777 Fantasy Way',
                'city' => 'Seattle',
                'province' => 'WA',
                'zip' => '98101',
                'country' => 'USA',
            ],
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cust_001',
            'X-Shopify-Topic' => 'customers/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/customers', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Customer update webhook processed successfully.',
            'zoho_synced' => true,
        ]);

        $this->assertDatabaseHas('customers', [
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9001',
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
            'email' => 'alice@example.com',
            'zoho_contact_id' => 'zoho_contact_alice',
        ]);
    }

    public function test_customer_webhook_idempotency() {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'contacts' => [],
                    ], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'contact' => [
                            'contact_id' => 'zoho_contact_idempotent',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $payload = json_encode([
            'id' => 9002,
            'first_name' => 'Idempotent',
            'last_name' => 'Test',
            'email' => 'idempotent@example.com',
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        // First delivery
        $response1 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cust_duplicate_id',
            'X-Shopify-Topic' => 'customers/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/customers', json_decode($payload, true));

        $response1->assertStatus(200);
        $response1->assertJson(['message' => 'Customer update webhook processed successfully.']);

        // Duplicate delivery
        $response2 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cust_duplicate_id',
            'X-Shopify-Topic' => 'customers/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/customers', json_decode($payload, true));

        $response2->assertStatus(200);
        $response2->assertJson(['message' => 'Webhook already processed.']);
    }

    public function test_customer_tenant_isolation() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $otherShop = Shop::create([
            'shop_domain' => 'other-shop.myshopify.com',
            'access_token' => 'shpat_other_token',
        ]);

        $payload = json_encode([
            'id' => 9003,
            'first_name' => 'Tenant',
            'last_name' => 'Isolated',
            'email' => 'isolated@example.com',
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $otherShop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cust_tenant_01',
            'X-Shopify-Topic' => 'customers/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/customers', json_decode($payload, true));

        $response->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'shop_id' => $otherShop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9003',
        ]);

        $this->assertDatabaseMissing('customers', [
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9003',
        ]);
    }
}
