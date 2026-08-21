<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoPreflightService;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoPreflightSetupTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private ZohoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'preflight-test.myshopify.com',
            'access_token' => 'shpat_test_token_123',
            'currency' => 'USD',
        ]);

        $this->connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'organization_id' => '60082438046',
            'organization_name' => 'Preflight Test Org',
            'access_token' => 'z_access_token_123',
            'refresh_token' => 'z_refresh_token_123',
            'expires_at' => now()->addHour(),
            'api_domain' => 'www.zohoapis.com',
            'setup_status' => 'connected',
        ]);
    }

    public function test_preflight_reuses_existing_custom_fields_and_price_list()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/fields*entity=item*' => Http::response([
                'code' => 0,
                'fields' => [
                    [
                        'field_id' => '460000000017001',
                        'customfield_id' => '460000000017001',
                        'api_name' => 'cf_shopify_variant_id',
                        'label_name' => 'Shopify Variant ID',
                    ],
                    [
                        'field_id' => '460000000017002',
                        'customfield_id' => '460000000017002',
                        'api_name' => 'cf_shopify_product_id',
                        'label_name' => 'Shopify Product ID',
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/fields*entity=salesorder*' => Http::response([
                'code' => 0,
                'fields' => [
                    [
                        'field_id' => '460000000017003',
                        'customfield_id' => '460000000017003',
                        'api_name' => 'cf_shopify_order_id',
                        'label_name' => 'Shopify Order ID',
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/fields*entity=invoice*' => Http::response([
                'code' => 0,
                'fields' => [
                    [
                        'field_id' => '460000000017003',
                        'customfield_id' => '460000000017003',
                        'api_name' => 'cf_shopify_order_id',
                        'label_name' => 'Shopify Order ID',
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/pricebooks*' => Http::response([
                'code' => 0,
                'pricebooks' => [
                    [
                        'pricebook_id' => '460000000018001',
                        'name' => 'Shopify USD Price List',
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/currencies*' => Http::response([
                'code' => 0,
                'currencies' => [
                    ['currency_id' => '460000000000097', 'currency_code' => 'USD'],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/taxes*' => Http::response([
                'code' => 0,
                'taxes' => [
                    ['tax_id' => '460000000000001', 'tax_name' => 'Sales Tax', 'tax_percentage' => 10],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response([
                'code' => 0,
                'items' => [],
            ], 200),
        ]);

        $service = new ZohoPreflightService();
        $result = $service->run($this->shop);

        $this->assertTrue($result['success']);
        $this->assertEquals('ready', $result['status']);
        $this->assertEquals('Integration Ready', $result['readiness_label']);

        $this->connection->refresh();
        $this->assertEquals('ready', $this->connection->setup_status);
        $this->assertEquals('Integration Ready', $this->connection->readiness_label);
        $this->assertEquals('460000000017001', $this->connection->custom_field_mappings['item']['cf_shopify_variant_id']['field_id']);
        $this->assertEquals('460000000017002', $this->connection->custom_field_mappings['item']['cf_shopify_product_id']['field_id']);
        $this->assertEquals('460000000017003', $this->connection->custom_field_mappings['salesorder']['cf_shopify_order_id']['field_id']);
    }

    public function test_preflight_provisions_missing_custom_fields()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/fields*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Custom field created',
                        'customfield' => [
                            'field_id' => '460000000099001',
                            'customfield_id' => '460000000099001',
                            'api_name' => 'cf_shopify_variant_id',
                            'label_name' => 'Shopify Variant ID',
                        ],
                    ], 200);
                }
                return Http::response(['code' => 0, 'fields' => []], 200);
            },
            'https://www.zohoapis.com/books/v3/pricebooks*' => Http::response([
                'code' => 0,
                'pricebooks' => [
                    [
                        'pricebook_id' => '460000000018001',
                        'name' => 'Shopify USD Price List',
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/currencies*' => Http::response([
                'code' => 0,
                'currencies' => [
                    ['currency_id' => '460000000000097', 'currency_code' => 'USD'],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/taxes*' => Http::response([
                'code' => 0,
                'taxes' => [],
            ], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response([
                'code' => 0,
                'items' => [],
            ], 200),
        ]);

        $service = new ZohoPreflightService();
        $result = $service->run($this->shop);

        $this->assertTrue($result['success']);
        $this->assertEquals('ready', $result['status']);
        $this->assertNotEmpty($result['summary']['created_configurations']);

        $this->connection->refresh();
        $this->assertEquals('ready', $this->connection->setup_status);
        $this->assertEquals('460000000099001', $this->connection->custom_field_mappings['item']['cf_shopify_variant_id']['field_id']);
    }

    public function test_preflight_marks_setup_required_when_currency_missing()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    ['field_id' => '111', 'api_name' => 'cf_shopify_variant_id', 'label_name' => 'Shopify Variant ID'],
                    ['field_id' => '222', 'api_name' => 'cf_shopify_product_id', 'label_name' => 'Shopify Product ID'],
                    ['field_id' => '333', 'api_name' => 'cf_shopify_order_id', 'label_name' => 'Shopify Order ID'],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/pricebooks*' => Http::response([
                'code' => 0,
                'pricebooks' => [],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/currencies*' => Http::response([
                'code' => 0,
                'currencies' => [
                    ['currency_id' => '100', 'currency_code' => 'INR'],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/taxes*' => Http::response([
                'code' => 0,
                'taxes' => [],
            ], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response([
                'code' => 0,
                'items' => [],
            ], 200),
        ]);

        $service = new ZohoPreflightService();
        $result = $service->run($this->shop);

        $this->assertTrue($result['success']);
        $this->assertEquals('setup_required', $result['status']);
        $this->assertEquals('Connected — Setup Required', $result['readiness_label']);
        $this->assertNotEmpty($result['summary']['missing_configurations']);

        $this->connection->refresh();
        $this->assertEquals('setup_required', $this->connection->setup_status);
        $this->assertEquals('Connected — Setup Required', $this->connection->readiness_label);
    }

    public function test_zoho_service_uses_stored_custom_field_mappings()
    {
        $this->connection->update([
            'custom_field_mappings' => [
                'item' => [
                    'cf_shopify_variant_id' => ['field_id' => '998877'],
                    'cf_shopify_product_id' => ['field_id' => '998866'],
                ],
                'salesorder' => [
                    'cf_shopify_order_id' => ['field_id' => '998855'],
                ],
            ],
        ]);

        $zohoService = new ZohoService($this->shop);

        $this->assertEquals('998877', $zohoService->getShopifyVariantFieldId());
        $this->assertEquals('998866', $zohoService->getShopifyProductFieldId());
        $this->assertEquals('998855', $zohoService->getShopifyOrderFieldId('salesorder'));
    }

    public function test_preflight_controller_endpoints()
    {
        $this->withoutMiddleware();

        $response = $this->withSession(['shop_domain' => $this->shop->shop_domain])
            ->get("/api/zoho/preflight?shop={$this->shop->shop_domain}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_connected' => true,
                'setup_status' => 'connected',
            ]);
    }
}
