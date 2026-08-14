<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\ZohoOauthState;
use App\Services\ZohoDatacenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ZohoAuthController extends Controller {
    /**
     * Protected endpoint to initiate Zoho Books OAuth flow from authenticated Shopify app context.
     * Always uses the global Multi-DC authorization entry point (accounts.zoho.com).
     */
    public function initiate(Request $request) {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'error' => 'No Shopify shop installed.',
            ], 401);
        }

        $host = $request->input('host') ?: $request->query('host');

        if (!$host || !$this->isValidShopifyHost($host)) {
            return response()->json([
                'error' => 'Invalid or missing Shopify host parameter.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Cryptographically Secure OAuth State & Store SHA-256 Hash
        |--------------------------------------------------------------------------
        */

        $rawState = bin2hex(random_bytes(32));
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $shop->id,
            'host' => $host,
            'expires_at' => now()->addMinutes(15),
            'consumed_at' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Global OAuth Initiation Entry Point (accounts.zoho.com)
        |--------------------------------------------------------------------------
        */

        $clientId = config('services.zoho.client_id') ?: env('ZOHO_CLIENT_ID');
        $redirectUri = config('services.zoho.redirect_uri') ?: env('ZOHO_REDIRECT_URI');

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => implode(',', [
                'ZohoBooks.settings.READ',
                'ZohoBooks.settings.CREATE',
                'ZohoBooks.settings.UPDATE',
                'ZohoInventory.items.READ',
                'ZohoInventory.items.CREATE',
                'ZohoInventory.items.UPDATE',
                'ZohoInventory.inventoryadjustments.READ',
                'ZohoInventory.inventoryadjustments.CREATE',
                'ZohoInventory.inventoryadjustments.UPDATE',
            ]),
            'redirect_uri' => $redirectUri,
            'state' => $rawState,
        ]);

        $accountsBaseUrl = ZohoDatacenter::getInitiationAccountsUrl($shop);
        $url = $accountsBaseUrl . '/oauth/v2/auth?' . $params;

        return response()->json([
            'success' => true,
            'redirect_url' => $url,
        ]);
    }

    /**
     * Handle public Zoho OAuth callback.
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $rawState = $request->query('state');

        if (!$code || !$rawState) {
            return response()->json([
                'error' => 'Authorization code or state missing.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate & Consume Server-Side OAuth Hashed State Record
        |--------------------------------------------------------------------------
        */

        $stateHash = hash('sha256', $rawState);
        $stateRecord = ZohoOauthState::where('state', $stateHash)->first();

        if (!$stateRecord) {
            return response()->json([
                'error' => 'Invalid Zoho OAuth state.',
            ], 403);
        }

        if ($stateRecord->consumed_at !== null) {
            return response()->json([
                'error' => 'Zoho OAuth state has already been used.',
            ], 403);
        }

        if (now()->gte($stateRecord->expires_at)) {
            return response()->json([
                'error' => 'Zoho OAuth state has expired.',
            ], 403);
        }

        // Consume state immediately
        $stateRecord->update(['consumed_at' => now()]);

        /*
        |--------------------------------------------------------------------------
        | Recover Exact Shopify Shop from State Record
        |--------------------------------------------------------------------------
        */

        $shopModel = Shop::find($stateRecord->shop_id);

        if (!$shopModel) {
            return response()->json([
                'error' => 'Associated Shopify shop not found.',
            ], 404);
        }

        $host = $stateRecord->host;

        if (!$this->isValidShopifyHost($host)) {
            return response()->json([
                'error' => 'Invalid Shopify host destination.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve and Validate Accounts URL from OAuth Callback Parameters
        |--------------------------------------------------------------------------
        */

        $accountsServerParam = $request->query('accounts-server');
        $validatedAccountsServer = null;

        if ($accountsServerParam !== null && trim($accountsServerParam) !== '') {
            $validatedAccountsServer = ZohoDatacenter::validateAccountsUrl($accountsServerParam);
            if (!$validatedAccountsServer) {
                return response()->json([
                    'error' => 'Invalid or untrusted Zoho accounts-server parameter.',
                ], 400);
            }
            $tokenExchangeAccountsUrl = $validatedAccountsServer;
        } else {
            $tokenExchangeAccountsUrl = ZohoDatacenter::resolveAccountsUrl(
                null,
                $request->query('location')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Exchange Authorization Code at Resolved Accounts Server
        |--------------------------------------------------------------------------
        */

        $clientId = config('services.zoho.client_id') ?: env('ZOHO_CLIENT_ID');
        $clientSecret = config('services.zoho.client_secret') ?: env('ZOHO_CLIENT_SECRET');
        $redirectUri = config('services.zoho.redirect_uri') ?: env('ZOHO_REDIRECT_URI');

        $response = Http::asForm()->post(
            $tokenExchangeAccountsUrl . '/oauth/v2/token',
            [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]
        );

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Zoho token exchange failed.',
                'response' => $response->json(),
            ], 500);
        }

        $tokenData = $response->json();

        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;

        if (!$accessToken) {
            return response()->json([
                'error' => 'Zoho did not return an access token.',
            ], 500);
        }

        if (!$refreshToken) {
            return response()->json([
                'error' => 'Zoho did not return a refresh token.',
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate API Domain & Resolve Accounts & API URLs for Merchant
        |--------------------------------------------------------------------------
        */

        $rawApiDomain = $tokenData['api_domain'] ?? null;

        if ($rawApiDomain !== null && trim($rawApiDomain) !== '') {
            $apiUrl = ZohoDatacenter::validateApiUrl($rawApiDomain);
            if (!$apiUrl) {
                return response()->json([
                    'error' => 'Invalid or untrusted Zoho API domain in token response.',
                ], 400);
            }
        } else {
            $apiUrl = ZohoDatacenter::validateApiUrl(config('services.zoho.api_url') ?: env('ZOHO_API_URL')) ?? 'https://www.zohoapis.com';
        }

        if (!empty($validatedAccountsServer)) {
            $accountsUrl = $validatedAccountsServer;
        } else {
            $accountsUrl = ZohoDatacenter::getAccountsUrlForApiUrl($apiUrl);
        }

        $apiDomainHost = parse_url($apiUrl, PHP_URL_HOST);

        /*
        |--------------------------------------------------------------------------
        | Get Zoho Books Organizations using Connection-Specific API URL
        |--------------------------------------------------------------------------
        */

        $organizationResponse = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(
            $apiUrl . '/books/v3/organizations'
        );

        if (!$organizationResponse->successful()) {
            return response()->json([
                'error' => 'Failed to fetch Zoho organizations.',
                'response' => $organizationResponse->json(),
            ], 500);
        }

        $organizations = $organizationResponse->json('organizations', []);

        if (empty($organizations)) {
            return response()->json([
                'error' => 'No Zoho Books organization found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Select Default Organization
        |--------------------------------------------------------------------------
        */

        $organization = collect($organizations)->firstWhere('is_default_org', true) ?? $organizations[0];
        $organizationId = $organization['organization_id'] ?? null;

        if (!$organizationId) {
            return response()->json([
                'error' => 'Zoho organization ID not found.',
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Save / Update Zoho Connection for the Recovered Shop (Reuses Row if Exists)
        |--------------------------------------------------------------------------
        */

        ZohoConnection::updateOrCreate(
            [
                'shop_id' => $shopModel->id,
            ],
            [
                'is_active' => true,
                'organization_id' => $organizationId,
                'organization_name' => $organization['name'] ?? $organization['organization_name'] ?? null,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'accounts_url' => $accountsUrl,
                'api_url' => $apiUrl,
                'api_domain' => $apiDomainHost,
                'data_center' => strtolower($apiDomainHost ?? ''),
                'scope' => $tokenData['scope'] ?? 'ZohoBooks.settings.READ,ZohoBooks.settings.CREATE,ZohoBooks.settings.UPDATE,ZohoInventory.items.READ,ZohoInventory.items.CREATE,ZohoInventory.items.UPDATE,ZohoInventory.inventoryadjustments.READ,ZohoInventory.inventoryadjustments.CREATE,ZohoInventory.inventoryadjustments.UPDATE',
                'connected_at' => now(),
                'disconnected_at' => null,
                'expires_at' => now()->addSeconds(
                    $tokenData['expires_in'] ?? 3600
                ),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Return to Shopify Embedded App Root
        |--------------------------------------------------------------------------
        */

        $decodedHost = base64_decode(strtr($host, '-_', '+/'));

        $adminUrl = 'https://' . $decodedHost . '/apps/zoho-books-integration-20';

        $query = http_build_query([
            'shop' => $shopModel->shop_domain,
            'host' => $host,
        ]);

        return redirect()->away($adminUrl . '?' . $query);
    }

    /**
     * Validate base64-encoded Shopify host string.
     */
    private function isValidShopifyHost(string $host): bool
    {
        if (trim($host) === '') {
            return false;
        }

        $decoded = base64_decode(strtr($host, '-_', '+/'), true);

        if ($decoded === false || trim($decoded) === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(admin\.shopify\.com\/store\/[a-zA-Z0-9\-]+|[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com(\/admin)?)$/',
            $decoded
        );
    }
}
