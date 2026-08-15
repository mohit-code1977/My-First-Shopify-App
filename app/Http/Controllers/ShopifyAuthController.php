<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\Shop;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Log;

class ShopifyAuthController extends Controller {
    public function __construct(
        private ShopifyService $shopifyService
    ) {}

    public function install(Request $request) {
        $shop = $request->query('shop');

        if (!$shop) {
            return response()->json([
                'error' => 'Missing shop parameter'
            ], 400);
        }

        // Validate Shopify shop domain
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
            return response()->json([
                'error' => 'Invalid shop domain'
            ], 400);
        }

        $scopes = env('SHOPIFY_SCOPES');
        $apiKey = env('SHOPIFY_API_KEY');

            
        $redirectUri = rtrim(env('SHOPIFY_APP_URL'), '/')
            . env('SHOPIFY_REDIRECT_URI');

        // Generate CSRF protection state
        $state = Str::random(32);

        session([
            'shopify_oauth_state' => $state,
            'shopify_oauth_shop' => $shop,
        ]);

        $installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id' => $apiKey,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect($installUrl);
    }

    public function callback(Request $request) {
        $shop = $request->query('shop');
        $code = $request->query('code');
        $hmac = $request->query('hmac');
        $state = $request->query('state');
        $timestamp = $request->query('timestamp');
        $host = $request->query('host');

        // Required parameters
        if (!$shop || !$code || !$hmac || !$state || !$timestamp || !$host) {
            return response()->json([
                'error' => 'Missing required OAuth parameters'
            ], 400);
        }

        // Validate shop domain
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
            return response()->json([
                'error' => 'Invalid shop domain'
            ], 400);
        }

        // Validate state
        $savedState = session('shopify_oauth_state');
        $savedShop = session('shopify_oauth_shop');

        if (!$savedState || !hash_equals($savedState, $state)) {
            return response()->json([
                'error' => 'Invalid OAuth state'
            ], 403);
        }

        if ($savedShop !== $shop) {
            return response()->json([
                'error' => 'Shop mismatch'
            ], 403);
        }

        // Validate timestamp
        if (abs(time() - (int) $timestamp) > 86400) {
            return response()->json([
                'error' => 'OAuth request expired'
            ], 400);
        }

        // Validate HMAC
        $params = $request->query();

        $receivedHmac = $params['hmac'];

        unset($params['hmac']);

        ksort($params, SORT_STRING);

        $message = http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $calculatedHmac = hash_hmac(
            'sha256',
            $message,
            env('SHOPIFY_API_SECRET')
        );

        if (!hash_equals($calculatedHmac, $receivedHmac)) {
            return response()->json([
                'error' => 'Invalid HMAC'
            ], 403);
        }

        // Exchange authorization code for access token
        $response = Http::asForm()
            ->acceptJson()
            ->post("https://{$shop}/admin/oauth/access_token", [
                'client_id' => env('SHOPIFY_API_KEY'),
                'client_secret' => env('SHOPIFY_API_SECRET'),
                'code' => $code,
                'expiring' => 1,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Access token exchange failed',
                'shopify_response' => $response->json(),
            ], 500);
        }

        $tokenData = $response->json();

        $accessToken = $tokenData['access_token'] ?? null;
        $scope = $tokenData['scope'] ?? null;

        if (!$accessToken) {
            return response()->json([
                'error' => 'Shopify did not return an access token'
            ], 500);
        }


        /*------------- Insert/Update values in DB ----------*/
        $shopModel = Shop::updateOrCreate(
            [
                'shop_domain' => $shop,
            ],
            [
                'access_token' => $accessToken,
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'scope' => $scope,
                'access_token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
            ]
        );

        // Register Shopify webhooks
        try {
            $this->shopifyService->registerProductUpdateWebhook($shopModel);
        } catch (\Throwable $e) {
            Log::error('Shopify products/update webhook registration failed', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->shopifyService->registerInventoryLevelUpdateWebhook($shopModel);
        } catch (\Throwable $e) {
            Log::error('Shopify inventory_levels/update webhook registration failed', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
        }


        // Clear OAuth session data
        session()->forget([
            'shopify_oauth_state',
            'shopify_oauth_shop',
        ]);

        // Temporary testing response.
        // DO NOT expose the actual access token.
        return redirect()->route('zoho.sync', [
            'shop' => $shop,
            'host' => $host,
        ]);
    }
}
