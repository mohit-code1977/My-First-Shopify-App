<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopUS;
    private ZohoConnection $connUS;

    private Shop $shopEU;
    private ZohoConnection $connEU;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant 1: US Data Center
        $this->shopUS = Shop::create([
            'shop_domain' => 'us-capability-test.myshopify.com',
            'access_token' => 'shpat_us_token',
        ]);

        $this->connUS = ZohoConnection::create([
            'shop_id' => $this->shopUS->id,
            'organization_id' => 'org_us_100',
            'access_token' => 'us_access_token',
            'refresh_token' => 'us_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
            'inventory_capability' => 'unknown',
        ]);

        // Tenant 2: EU Data Center
        $this->shopEU = Shop::create([
            'shop_domain' => 'eu-capability-test.myshopify.com',
            'access_token' => 'shpat_eu_token',
        ]);

        $this->connEU = ZohoConnection::create([
            'shop_id' => $this->shopEU->id,
            'organization_id' => 'org_eu_200',
            'access_token' => 'eu_access_token',
            'refresh_token' => 'eu_refresh_token',
            'accounts_url' => 'https://accounts.zoho.eu',
            'api_url' => 'https://www.zohoapis.eu',
            'expires_at' => now()->addHour(),
            'inventory_capability' => 'unknown',
        ]);
    }

    public function test_detects_zoho_inventory_capability_and_uses_inventory_v1_endpoint(): void
    {
        Http::fake([
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 0, 'items' => []], 200),
            'https://www.zohoapis.com/books/v3/items/item_inv_1*' => Http::response(['code' => 0, 'item' => ['actual_available_stock' => 5]], 200),
            'https://www.zohoapis.com/inventory/v1/inventoryadjustments*' => Http::response(['code' => 0, 'message' => 'Adjusted'], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_INVENTORY, $capability);

        $this->connUS->refresh();
        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_INVENTORY, $this->connUS->inventory_capability);

        $product = Product::create([
            'shop_id' => $this->shopUS->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Test Item',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1000',
            'title' => 'Default',
            'inventory_quantity' => 15,
            'zoho_item_id' => 'item_inv_1',
        ]);

        $res = $service->syncInventory($variant);

        $this->assertTrue($res['success']);
        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_INVENTORY, $res['capability']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/inventory/v1/inventoryadjustments')
                && $request->method() === 'POST'
                && $request['line_items'][0]['quantity_adjusted'] === 10;
        });
    }

    public function test_detects_zoho_erp_capability_and_uses_erp_v3_endpoint(): void
    {
        Http::fake([
            'https://www.zohoapis.eu/inventory/v1/items*' => Http::response(['code' => 6018], 403),
            'https://www.zohoapis.eu/erp/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
            'https://www.zohoapis.eu/books/v3/items/item_erp_1*' => Http::response(['code' => 0, 'item' => ['actual_available_stock' => 5]], 200),
            'https://www.zohoapis.eu/erp/v3/inventoryadjustments*' => Http::response(['code' => 0, 'message' => 'Adjusted'], 200),
        ]);

        $service = new ZohoService($this->shopEU);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_ERP, $capability);

        $this->connEU->refresh();
        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_ERP, $this->connEU->inventory_capability);

        $product = Product::create([
            'shop_id' => $this->shopEU->id,
            'shopify_product_id' => 'gid://shopify/Product/200',
            'title' => 'EU Test Item',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2000',
            'title' => 'Default',
            'inventory_quantity' => 12,
            'zoho_item_id' => 'item_erp_1',
        ]);

        $res = $service->syncInventory($variant);

        $this->assertTrue($res['success']);
        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_ERP, $res['capability']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'zohoapis.eu/erp/v3/inventoryadjustments')
                && $request->method() === 'POST'
                && $request['line_items'][0]['quantity_adjusted'] === 7;
        });
    }

    public function test_detects_books_native_capability_and_returns_clear_capability_error_without_false_success(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/items/item_books_1*' => Http::response(['code' => 0, 'item' => ['item_id' => 'item_books_1', 'actual_available_stock' => 5]], 200),
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 6018], 403),
            'https://www.zohoapis.com/erp/v3/items*' => Http::response(['code' => 6018], 403),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $capability);

        $product = Product::create([
            'shop_id' => $this->shopUS->id,
            'shopify_product_id' => 'gid://shopify/Product/300',
            'title' => 'Books Native Item',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/3000',
            'title' => 'Default',
            'inventory_quantity' => 20,
            'zoho_item_id' => 'item_books_1',
        ]);

        $res = $service->syncInventory($variant);

        // MUST NOT false-report success
        $this->assertFalse($res['success']);
        $this->assertEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $res['capability']);
        $this->assertStringContainsString('Automatic inventory adjustment requires Zoho Inventory/ERP API access', $res['message']);

        // MUST NOT hit /inventory/v1 or /erp/v3
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/inventory/v1/inventoryadjustments') || str_contains($request->url(), '/erp/v3/inventoryadjustments');
        });

        // SyncHistory entry created
        $history = SyncHistory::where('shop_id', $this->shopUS->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals('skipped', $history->status);
        $this->assertStringContainsString('Books native inventory adjustments are available in the UI', $history->message);
    }

    public function test_detects_unavailable_capability_when_all_probes_fail(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/items/item_unavail*' => Http::response(['code' => 0, 'item' => ['item_id' => 'item_unavail', 'actual_available_stock' => 5]], 200),
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 6018], 403),
            'https://www.zohoapis.com/erp/v3/items*' => Http::response(['code' => 57], 403),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 57], 403),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_UNAVAILABLE, $capability);

        $product = Product::create([
            'shop_id' => $this->shopUS->id,
            'shopify_product_id' => 'gid://shopify/Product/400',
            'title' => 'Unavailable Item',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/4000',
            'title' => 'Default',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'item_unavail',
        ]);

        $res = $service->syncInventory($variant);

        $this->assertFalse($res['success']);
        $this->assertEquals(ZohoService::CAPABILITY_UNAVAILABLE, $res['capability']);
        $this->assertStringContainsString('Zoho inventory sync is unavailable', $res['message']);
    }

    public function test_inventory_401_or_403_or_code_57_does_not_become_books_native(): void
    {
        Http::fake([
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 57, 'message' => 'Not authorized'], 401),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_UNAVAILABLE, $capability);

        $this->connUS->refresh();
        $this->assertNotEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $this->connUS->inventory_capability);
    }

    public function test_inventory_5xx_server_error_does_not_become_books_native(): void
    {
        Http::fake([
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 1000, 'message' => 'Internal Server Error'], 500),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_UNAVAILABLE, $capability);

        $this->connUS->refresh();
        $this->assertNotEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $this->connUS->inventory_capability);
    }

    public function test_inventory_timeout_does_not_become_books_native(): void
    {
        Http::fake([
            'https://www.zohoapis.com/inventory/v1/items*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_UNAVAILABLE, $capability);

        $this->connUS->refresh();
        $this->assertNotEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $this->connUS->inventory_capability);
    }

    public function test_inventory_404_can_continue_to_erp(): void
    {
        Http::fake([
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 404, 'message' => 'Not Found'], 404),
            'https://www.zohoapis.com/erp/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_ERP, $capability);
    }

    public function test_erp_404_can_continue_to_books(): void
    {
        Http::fake([
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 6018, 'message' => 'Disabled'], 403),
            'https://www.zohoapis.com/erp/v3/items*' => Http::response(['code' => 404, 'message' => 'Not Found'], 404),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $service = new ZohoService($this->shopUS);
        $capability = $service->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $capability);
    }

    public function test_tenant_isolation_capabilities_do_not_bleed_across_shops(): void
    {
        Http::fake([
            // US Probe -> Zoho Inventory
            'https://www.zohoapis.com/inventory/v1/items*' => Http::response(['code' => 0, 'items' => []], 200),
            // EU Probe -> Books Native
            'https://www.zohoapis.eu/inventory/v1/items*' => Http::response(['code' => 6018], 403),
            'https://www.zohoapis.eu/erp/v3/items*' => Http::response(['code' => 6018], 403),
            'https://www.zohoapis.eu/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $serviceUS = new ZohoService($this->shopUS);
        $serviceEU = new ZohoService($this->shopEU);

        $capUS = $serviceUS->detectInventoryCapability(true);
        $capEU = $serviceEU->detectInventoryCapability(true);

        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_INVENTORY, $capUS);
        $this->assertEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $capEU);

        $this->connUS->refresh();
        $this->connEU->refresh();

        $this->assertEquals(ZohoService::CAPABILITY_ZOHO_INVENTORY, $this->connUS->inventory_capability);
        $this->assertEquals(ZohoService::CAPABILITY_BOOKS_NATIVE, $this->connEU->inventory_capability);
    }
}
