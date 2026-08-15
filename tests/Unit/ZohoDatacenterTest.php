<?php

namespace Tests\Unit;

use App\Http\Controllers\ZohoAuthController;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\ZohoOauthState;
use App\Services\ZohoDatacenter;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoDatacenterTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopUS;
    private Shop $shopEU;
    private Shop $shopIN;
    private string $hostB64;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopUS = Shop::create([
            'shop_domain' => 'us-store.myshopify.com',
            'access_token' => 'shpat_us_123',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->shopEU = Shop::create([
            'shop_domain' => 'eu-store.myshopify.com',
            'access_token' => 'shpat_eu_123',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->shopIN = Shop::create([
            'shop_domain' => 'in-store.myshopify.com',
            'access_token' => 'shpat_in_123',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->hostB64 = base64_encode('admin.shopify.com/store/us-store');
    }

    public function test_every_supported_zoho_dc_is_accepted()
    {
        $expectedDCs = [
            'https://accounts.zoho.com' => 'https://www.zohoapis.com',
            'https://accounts.zoho.eu' => 'https://www.zohoapis.eu',
            'https://accounts.zoho.in' => 'https://www.zohoapis.in',
            'https://accounts.zoho.com.au' => 'https://www.zohoapis.com.au',
            'https://accounts.zoho.jp' => 'https://www.zohoapis.jp',
            'https://accounts.zoho.ca' => 'https://www.zohoapis.ca',
            'https://accounts.zoho.com.cn' => 'https://www.zohoapis.com.cn',
            'https://accounts.zoho.sa' => 'https://www.zohoapis.sa',
        ];

        foreach ($expectedDCs as $accountsUrl => $apiUrl) {
            $this->assertEquals($accountsUrl, ZohoDatacenter::validateAccountsUrl($accountsUrl));
            $this->assertEquals($apiUrl, ZohoDatacenter::validateApiUrl($apiUrl));
            $this->assertEquals($accountsUrl, ZohoDatacenter::getAccountsUrlForApiUrl($apiUrl));
        }
    }

    public function test_non_zoho_urls_are_rejected()
    {
        $untrustedUrls = [
            'https://evil.com',
            'https://accounts.zoho.com.attacker.com',
            'https://www.zohoapis.com.attacker.com',
            'https://phishing-zoho.com',
            'https://accounts.zoho.com/malicious/path', // domain is okay, but url should sanitize to host root
        ];

        $this->assertNull(ZohoDatacenter::validateAccountsUrl('https://evil.com'));
        $this->assertNull(ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.com.attacker.com'));
        $this->assertNull(ZohoDatacenter::validateApiUrl('https://www.zohoapis.com.attacker.com'));
    }

    public function test_http_endpoints_are_rejected()
    {
        $this->assertNull(ZohoDatacenter::validateAccountsUrl('http://accounts.zoho.com'));
        $this->assertNull(ZohoDatacenter::validateApiUrl('http://www.zohoapis.com'));
        $this->assertNull(ZohoDatacenter::validateAccountsUrl('http://accounts.zoho.eu'));
        $this->assertNull(ZohoDatacenter::validateApiUrl('http://www.zohoapis.in'));
    }

    public function test_us_domain_response_stores_correct_urls()
    {
        Http::fake([
            'https://accounts.zoho.com/oauth/v2/token' => Http::response([
                'access_token' => 'us_access_token',
                'refresh_token' => 'us_refresh_token',
                'api_domain' => 'https://www.zohoapis.com',
                'expires_in' => 3600,
            ], 200),
            'https://www.zohoapis.com/books/v3/organizations' => Http::response([
                'organizations' => [
                    ['organization_id' => 'us_org_100', 'is_default_org' => true],
                ],
            ], 200),
        ]);

        $rawState = 'raw_us_state_token_123';
        ZohoOauthState::create([
            'state' => hash('sha256', $rawState),
            'shop_id' => $this->shopUS->id,
            'host' => $this->hostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'code_us',
            'state' => $rawState,
            'location' => 'us',
        ]);

        $response = $controller->callback($request);

        $this->assertTrue($response->isRedirection() || $response->isOk());

        $connection = ZohoConnection::where('shop_id', $this->shopUS->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('https://accounts.zoho.com', $connection->accounts_url);
        $this->assertEquals('https://www.zohoapis.com', $connection->api_url);
    }

    public function test_eu_domain_response_stores_correct_urls()
    {
        Http::fake([
            'https://accounts.zoho.eu/oauth/v2/token' => Http::response([
                'access_token' => 'eu_access_token',
                'refresh_token' => 'eu_refresh_token',
                'api_domain' => 'https://www.zohoapis.eu',
                'expires_in' => 3600,
            ], 200),
            'https://www.zohoapis.eu/books/v3/organizations' => Http::response([
                'organizations' => [
                    ['organization_id' => 'eu_org_200', 'is_default_org' => true],
                ],
            ], 200),
        ]);

        $rawState = 'raw_eu_state_token_456';
        ZohoOauthState::create([
            'state' => hash('sha256', $rawState),
            'shop_id' => $this->shopEU->id,
            'host' => $this->hostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'code_eu',
            'state' => $rawState,
            'location' => 'eu',
            'accounts-server' => 'https://accounts.zoho.eu',
        ]);

        $response = $controller->callback($request);

        $this->assertTrue($response->isRedirection() || $response->isOk());

        $connection = ZohoConnection::where('shop_id', $this->shopEU->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('https://accounts.zoho.eu', $connection->accounts_url);
        $this->assertEquals('https://www.zohoapis.eu', $connection->api_url);
    }

    public function test_india_domain_response_stores_correct_urls()
    {
        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response([
                'access_token' => 'in_access_token',
                'refresh_token' => 'in_refresh_token',
                'api_domain' => 'https://www.zohoapis.in',
                'expires_in' => 3600,
            ], 200),
            'https://www.zohoapis.in/books/v3/organizations' => Http::response([
                'organizations' => [
                    ['organization_id' => 'in_org_300', 'is_default_org' => true],
                ],
            ], 200),
        ]);

        $rawState = 'raw_in_state_token_789';
        ZohoOauthState::create([
            'state' => hash('sha256', $rawState),
            'shop_id' => $this->shopIN->id,
            'host' => $this->hostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'code_in',
            'state' => $rawState,
            'location' => 'in',
            'accounts-server' => 'https://accounts.zoho.in',
        ]);

        $response = $controller->callback($request);

        $this->assertTrue($response->isRedirection() || $response->isOk());

        $connection = ZohoConnection::where('shop_id', $this->shopIN->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('https://accounts.zoho.in', $connection->accounts_url);
        $this->assertEquals('https://www.zohoapis.in', $connection->api_url);
    }

    public function test_api_domain_is_validated_before_persistence()
    {
        Http::fake([
            '*/oauth/v2/token' => Http::response([
                'access_token' => 'test_access',
                'refresh_token' => 'test_refresh',
                'api_domain' => 'https://evil-untrusted-domain.com', // Malicious API domain
                'expires_in' => 3600,
            ], 200),
        ]);

        $rawState = 'raw_state_invalid_api_domain';
        ZohoOauthState::create([
            'state' => hash('sha256', $rawState),
            'shop_id' => $this->shopUS->id,
            'host' => $this->hostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'code_xyz',
            'state' => $rawState,
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(400, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid or untrusted Zoho API domain in token response.', $content['error']);

        // Verify connection was NOT persisted
        $this->assertDatabaseMissing('zoho_connections', [
            'shop_id' => $this->shopUS->id,
        ]);
    }

    public function test_untrusted_accounts_server_query_param_is_rejected()
    {
        $rawState = 'raw_state_untrusted_accounts_server';
        ZohoOauthState::create([
            'state' => hash('sha256', $rawState),
            'shop_id' => $this->shopUS->id,
            'host' => $this->hostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'code_xyz',
            'state' => $rawState,
            'accounts-server' => 'https://attacker-controlled-server.com',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(400, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid or untrusted Zoho accounts-server parameter.', $content['error']);
    }

    public function test_zoho_api_service_uses_connection_specific_api_url()
    {
        ZohoConnection::create([
            'shop_id' => $this->shopEU->id,
            'organization_id' => 'eu_org_200',
            'access_token' => 'eu_valid_access_token',
            'refresh_token' => 'eu_valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.eu',
            'api_url' => 'https://www.zohoapis.eu',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://www.zohoapis.eu/books/v3/items*' => Http::response([
                'items' => [
                    ['item_id' => 'item_101', 'name' => 'EU Item Test'],
                ],
            ], 200),
        ]);

        $service = new ZohoService($this->shopEU);
        $items = $service->getItems();

        $this->assertCount(1, $items);
        $this->assertEquals('item_101', $items[0]['item_id']);

        // Assert HTTP request was made to EU API endpoint, NOT US or global env
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_starts_with($request->url(), 'https://www.zohoapis.eu/books/v3/items');
        });
    }

    public function test_refresh_flow_uses_connection_specific_accounts_url()
    {
        ZohoConnection::create([
            'shop_id' => $this->shopIN->id,
            'organization_id' => 'in_org_300',
            'access_token' => 'expired_token',
            'refresh_token' => 'in_valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.in',
            'api_url' => 'https://www.zohoapis.in',
            'expires_at' => now()->subMinute(), // expired token triggers refresh
        ]);

        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response([
                'access_token' => 'new_refreshed_in_token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $service = new ZohoService($this->shopIN);
        $newToken = $service->getAccessToken();

        $this->assertEquals('new_refreshed_in_token', $newToken);

        // Assert refresh request was sent to https://accounts.zoho.in/oauth/v2/token
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://accounts.zoho.in/oauth/v2/token';
        });
    }

    public function test_one_shopify_shop_cannot_use_another_shops_zoho_endpoint()
    {
        ZohoConnection::create([
            'shop_id' => $this->shopUS->id,
            'organization_id' => 'us_org',
            'access_token' => 'us_token',
            'refresh_token' => 'us_ref',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shopEU->id,
            'organization_id' => 'eu_org',
            'access_token' => 'eu_token',
            'refresh_token' => 'eu_ref',
            'accounts_url' => 'https://accounts.zoho.eu',
            'api_url' => 'https://www.zohoapis.eu',
            'expires_at' => now()->addHour(),
        ]);

        $serviceUS = new ZohoService($this->shopUS);
        $serviceEU = new ZohoService($this->shopEU);

        $this->assertEquals('https://www.zohoapis.com', $serviceUS->getConnection()->api_url);
        $this->assertEquals('https://www.zohoapis.eu', $serviceEU->getConnection()->api_url);
        $this->assertNotEquals($serviceUS->getConnection()->api_url, $serviceEU->getConnection()->api_url);
    }

    public function test_connection_lacking_endpoint_fields_fails_with_clear_error()
    {
        // Legacy or incomplete connection missing accounts_url / api_url
        ZohoConnection::create([
            'shop_id' => $this->shopUS->id,
            'organization_id' => 'legacy_org',
            'access_token' => 'token',
            'refresh_token' => 'ref_token',
            'accounts_url' => null,
            'api_url' => null,
            'expires_at' => now()->addHour(),
        ]);

        $service = new ZohoService($this->shopUS);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zoho connection is missing or has an invalid api_url endpoint configuration.');

        $service->getItems();
    }
}
