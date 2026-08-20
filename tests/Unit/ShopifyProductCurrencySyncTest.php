<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Shop;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class ShopifyProductCurrencySyncTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shopUsd;
    protected Shop $shopEur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopUsd = Shop::create([
            'shop_domain' => 'usd-store.myshopify.com',
            'access_token' => 'shpat_usd_token_123',
            'currency' => 'USD',
        ]);

        $this->shopEur = Shop::create([
            'shop_domain' => 'eur-store.myshopify.com',
            'access_token' => 'shpat_eur_token_456',
            'currency' => 'EUR',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shopUsd->id,
            'organization_id' => '123456789',
            'access_token' => 'zoho_access_usd',
            'refresh_token' => 'zoho_refresh_usd',
            'api_url' => 'https://www.zohoapis.com',
            'accounts_url' => 'https://accounts.zoho.com',
            'inventory_capability' => ZohoService::CAPABILITY_BOOKS_NATIVE,
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shopEur->id,
            'organization_id' => '123456789',
            'access_token' => 'zoho_access_eur',
            'refresh_token' => 'zoho_refresh_eur',
            'api_url' => 'https://www.zohoapis.com',
            'accounts_url' => 'https://accounts.zoho.com',
            'inventory_capability' => ZohoService::CAPABILITY_BOOKS_NATIVE,
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }

    /**
     * Helper to extract body payload whether sent as raw JSON or JSONString form parameter.
     */
    protected function extractRequestBody($request): array
    {
        $bodyStr = (string) $request->body();
        $data = json_decode($bodyStr, true);
        if (!$data && str_contains($bodyStr, 'JSONString=')) {
            parse_str($bodyStr, $parsed);
            $data = json_decode($parsed['JSONString'] ?? '{}', true);
        }
        return $data ?: [];
    }

    /**
     * 1. Shopify USD → Raw numeric price ($699.95) preserved in Zoho item rate without exchange conversion.
     */
    public function test_shopify_usd_product_creation_preserves_numeric_rate()
    {
        $product = Product::create([
            'shop_id' => $this->shopUsd->id,
            'shopify_product_id' => 'gid://shopify/Product/9001',
            'title' => 'USD Laptop',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/90011',
            'title' => 'Default',
            'price' => '699.95',
            'sku' => 'LAPTOP-USD-1',
            'inventory_management' => 'shopify',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/organization*' => Http::response(['code' => 0, 'organization' => ['is_inventory_enabled' => true]], 200),
            'https://www.zohoapis.com/books/v3/items/editpage*' => Http::response(['code' => 0, 'inventory_accounts_list' => [['account_id' => 'acc_inv_1', 'is_active' => true]]], 200),
            'https://www.zohoapis.com/books/v3/settings/fields*' => Http::response(['code' => 0, 'fields' => [['field_id' => '111', 'customfield_id' => '111', 'api_name' => 'cf_shopify_variant_id']]], 200),
            'https://www.zohoapis.com/books/v3/items*' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'items' => []], 200);
                }
                $body = $this->extractRequestBody($request);
                // Confirm raw rate matches numeric variant price exactly 699.95
                $this->assertEquals(699.95, $body['rate'] ?? null);
                $this->assertEquals(699.95, $body['initial_stock_rate'] ?? null);
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been added.',
                    'item' => [
                        'item_id' => 'zoho_usd_item_111',
                        'rate' => 699.95,
                    ],
                ], 200);
            },
            'https://www.zohoapis.com/*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $service = new ZohoService($this->shopUsd);
        $result = $service->createItem($variant, 10);

        $this->assertTrue($result['created']);
        $this->assertEquals('zoho_usd_item_111', $result['zoho_item_id']);
        $this->assertEquals('zoho_usd_item_111', $variant->fresh()->zoho_item_id);
    }

    /**
     * 2. Shopify EUR → Raw numeric price (€699.95) preserved in Zoho item rate without exchange conversion.
     */
    public function test_shopify_eur_product_creation_preserves_numeric_rate()
    {
        $product = Product::create([
            'shop_id' => $this->shopEur->id,
            'shopify_product_id' => 'gid://shopify/Product/9002',
            'title' => 'EUR Laptop',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/90022',
            'title' => 'Default',
            'price' => '699.95',
            'sku' => 'LAPTOP-EUR-1',
            'inventory_management' => 'shopify',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/organization*' => Http::response(['code' => 0, 'organization' => ['is_inventory_enabled' => true]], 200),
            'https://www.zohoapis.com/books/v3/items/editpage*' => Http::response(['code' => 0, 'inventory_accounts_list' => [['account_id' => 'acc_inv_1', 'is_active' => true]]], 200),
            'https://www.zohoapis.com/books/v3/settings/fields*' => Http::response(['code' => 0, 'fields' => [['field_id' => '111', 'customfield_id' => '111', 'api_name' => 'cf_shopify_variant_id']]], 200),
            'https://www.zohoapis.com/books/v3/items*' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'items' => []], 200);
                }
                $body = $this->extractRequestBody($request);
                // Confirm EUR price 699.95 is sent directly without exchange conversion
                $this->assertEquals(699.95, $body['rate'] ?? null);
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been added.',
                    'item' => [
                        'item_id' => 'zoho_eur_item_222',
                        'rate' => 699.95,
                    ],
                ], 200);
            },
            'https://www.zohoapis.com/*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $service = new ZohoService($this->shopEur);
        $result = $service->createItem($variant);

        $this->assertTrue($result['created']);
        $this->assertEquals('zoho_eur_item_222', $result['zoho_item_id']);
    }

    /**
     * 3. Product Update → Raw numeric price updates correctly without currency conversion.
     */
    public function test_product_update_preserves_numeric_rate()
    {
        $product = Product::create([
            'shop_id' => $this->shopUsd->id,
            'shopify_product_id' => 'gid://shopify/Product/9003',
            'title' => 'Updated Product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/90033',
            'title' => 'Default',
            'price' => '129.99',
            'zoho_item_id' => 'zoho_item_existing_333',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_existing_333*' => function ($request) {
                $body = $this->extractRequestBody($request);
                $this->assertEquals(129.99, $body['rate'] ?? null);
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been updated.',
                    'item' => [
                        'item_id' => 'zoho_item_existing_333',
                        'rate' => 129.99,
                    ],
                ], 200);
            },
            'https://www.zohoapis.com/*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $service = new ZohoService($this->shopUsd);
        $result = $service->updateItem($variant);

        $this->assertTrue($result['updated']);
    }

    /**
     * 4. ShopifyService::fetchShopDetails → Syncs currencyCode from Shopify shop metadata.
     */
    public function test_fetch_shop_details_syncs_currency()
    {
        $shop = Shop::create([
            'shop_domain' => 'test-sync.myshopify.com',
            'access_token' => 'shpat_sync_123',
            'currency' => 'USD',
        ]);

        Http::fake([
            'https://test-sync.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'name' => 'Test Currency Shop',
                        'email' => 'admin@test.com',
                        'currencyCode' => 'GBP',
                        'myshopifyDomain' => 'test-sync.myshopify.com',
                    ],
                ],
            ], 200),
        ]);

        $shopifyService = new ShopifyService();
        $details = $shopifyService->fetchShopDetails($shop);

        $this->assertEquals('GBP', $details['currencyCode']);
        $this->assertEquals('GBP', $shop->fresh()->currency);
    }

    /**
     * 5. ZohoService::getCurrencies → Queries Zoho settings/currencies endpoint.
     */
    public function test_zoho_get_currencies_returns_currency_list()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/currencies*' => Http::response([
                'code' => 0,
                'message' => 'success',
                'currencies' => [
                    ['currency_id' => '111', 'currency_code' => 'USD', 'is_base_currency' => false],
                    ['currency_id' => '222', 'currency_code' => 'INR', 'is_base_currency' => true],
                ],
            ], 200),
            'https://www.zohoapis.com/*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $service = new ZohoService($this->shopUsd);
        $currencies = $service->getCurrencies();

        $this->assertCount(2, $currencies);
        $this->assertEquals('USD', $currencies[0]['currency_code']);
        $this->assertEquals('INR', $currencies[1]['currency_code']);
        $this->assertTrue($currencies[1]['is_base_currency']);
    }

    /**
     * 6. Unsupported/missing shop currency fallback.
     */
    public function test_missing_shop_currency_falls_back_gracefully()
    {
        $shop = Shop::create([
            'shop_domain' => 'no-currency.myshopify.com',
            'access_token' => 'shpat_no_curr',
            'currency' => 'USD',
        ]);

        Http::fake([
            'https://no-currency.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'errors' => ['Internal Server Error'],
            ], 500),
        ]);

        $shopifyService = new ShopifyService();
        $details = $shopifyService->fetchShopDetails($shop);

        $this->assertEquals('USD', $details['currencyCode']);
    }
}
