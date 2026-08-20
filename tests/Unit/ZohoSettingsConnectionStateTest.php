<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Http\Middleware\ShopifyAuthenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoSettingsConnectionStateTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ShopifyAuthenticate::class]);

        $this->shop = Shop::create([
            'shop_domain' => 'settings-state-test.myshopify.com',
            'access_token' => 'shpat_settings_test_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);
    }

    private function actingAsShop()
    {
        return $this->withHeaders([
            'X-Shop-Domain' => $this->shop->shop_domain,
            'Accept' => 'application/json',
        ])->withUnencryptedCookie('shop_domain', $this->shop->shop_domain);
    }

    public function test_settings_page_route_includes_active_connection_context()
    {
        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'organization_id' => 'org_active_999',
            'organization_name' => 'Active Test Org',
            'access_token' => 'at_123',
            'refresh_token' => 'rt_123',
            'accounts_url' => 'https://accounts.zoho.in',
            'api_url' => 'https://www.zohoapis.in',
            'api_domain' => 'www.zohoapis.in',
            'data_center' => 'in',
            'scope' => 'ZohoBooks.settings.READ',
            'connected_at' => now(),
        ]);

        $response = $this->actingAsShop()->get('/zoho/settings?shop=' . $this->shop->shop_domain);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Zoho/Settings')
            ->where('zohoConnected', true)
            ->where('zohoConnection.organization_id', 'org_active_999')
            ->where('zohoConnection.organization_name', 'Active Test Org')
        );
    }

    public function test_settings_page_route_returns_disconnected_context_when_no_active_connection()
    {
        $response = $this->actingAsShop()->get('/zoho/settings?shop=' . $this->shop->shop_domain);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Zoho/Settings')
            ->where('zohoConnected', false)
            ->where('zohoConnection', null)
        );
    }

    public function test_api_settings_data_returns_connected_connection_payload()
    {
        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'organization_id' => 'org_api_777',
            'organization_name' => 'API Test Org',
            'access_token' => 'at_api',
            'refresh_token' => 'rt_api',
            'accounts_url' => 'https://accounts.zoho.in',
            'api_url' => 'https://www.zohoapis.in',
            'api_domain' => 'www.zohoapis.in',
            'data_center' => 'in',
            'scope' => 'ZohoBooks.settings.READ',
            'connected_at' => now(),
        ]);

        $response = $this->actingAsShop()->getJson('/api/zoho/settings?shop=' . $this->shop->shop_domain);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'zohoConnection' => [
                'organization_id' => 'org_api_777',
                'organization_name' => 'API Test Org',
                'is_active' => true,
            ],
        ]);
    }

    public function test_api_settings_data_returns_null_zoho_connection_when_disconnected()
    {
        // Connection with is_active = false
        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => false,
            'organization_id' => 'org_inactive_000',
            'disconnected_at' => now(),
        ]);

        $response = $this->actingAsShop()->getJson('/api/zoho/settings?shop=' . $this->shop->shop_domain);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'zohoConnection' => null,
        ]);
    }
}
