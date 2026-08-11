<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ZohoConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ZohoAuthController extends Controller {
    public function connect(Request $request) {
        $shop = $request->query('shop');
        $host = $request->query('host');

        if (!$shop || !$host) {
            return response()->json([
                'error' => 'Missing Shopify shop context.'
            ], 400);
        }

        $state = Str::random(40);

        session([
            'zoho_oauth_state' => $state,
            'zoho_oauth_shop' => $shop,
            'zoho_oauth_host' => $host,
        ]);

        $params = http_build_query([
            'client_id' => env('ZOHO_CLIENT_ID'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => 'ZohoBooks.settings.READ,ZohoBooks.settings.CREATE,ZohoBooks.settings.UPDATE',
            'redirect_uri' => env('ZOHO_REDIRECT_URI'),
            'state' => $state,
        ]);

        $url = env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/auth?' . $params;

        return redirect($url);
    }

    public function callback(Request $request) {
        $code = $request->query('code');
        $state = $request->query('state');

        if (!$code || !$state) {
            return response()->json([
                'error' => 'Authorization code or state missing'
            ], 400);
        }

        $savedState = session('zoho_oauth_state');

        if (!$savedState || !hash_equals($savedState, $state)) {
            return response()->json([
                'error' => 'Invalid Zoho OAuth state'
            ], 403);
        }

        $shop = session('zoho_oauth_shop');
        $host = session('zoho_oauth_host');

        if (!$shop || !$host) {
            return response()->json([
                'error' => 'Shopify context missing'
            ], 400);
        }

        // Exchange authorization code for Zoho tokens
        $response = Http::asForm()->post(
            env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token',
            [
                'code' => $code,
                'client_id' => env('ZOHO_CLIENT_ID'),
                'client_secret' => env('ZOHO_CLIENT_SECRET'),
                'redirect_uri' => env('ZOHO_REDIRECT_URI'),
                'grant_type' => 'authorization_code',
            ]
        );

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Zoho token exchange failed',
                'response' => $response->json(),
            ], 500);
        }

        $tokenData = $response->json();

        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;

        if (!$accessToken) {
            return response()->json([
                'error' => 'Zoho did not return an access token'
            ], 500);
        }

        if (!$refreshToken) {
            return response()->json([
                'error' => 'Zoho did not return a refresh token'
            ], 500);
        }

        // Get Zoho Books organizations
        $organizationResponse = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(
            env('ZOHO_API_URL') . '/books/v3/organizations'
        );

        if (!$organizationResponse->successful()) {
            return response()->json([
                'error' => 'Failed to fetch Zoho organizations',
                'response' => $organizationResponse->json(),
            ], 500);
        }

        $organizations = $organizationResponse->json('organizations', []);

        if (empty($organizations)) {
            return response()->json([
                'error' => 'No Zoho Books organization found'
            ], 404);
        }

        // Select default organization
        $organization = collect($organizations)
            ->firstWhere('is_default_org', true)
            ?? $organizations[0];

        $organizationId = $organization['organization_id'];

        // Get the Shopify shop that started the OAuth flow
        $shopModel = Shop::where('shop_domain', $shop)->first();

        if (!$shopModel) {
            return response()->json([
                'error' => 'No Shopify shop installed'
            ], 404);
        }

        // Save or update Zoho connection
        ZohoConnection::updateOrCreate(
            [
                'shop_id' => $shopModel->id,
            ],
            [
                'organization_id' => $organizationId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds(
                    $tokenData['expires_in'] ?? 3600
                ),
            ]
        );

        // Clear OAuth session data
        session()->forget([
            'zoho_oauth_state',
            'zoho_oauth_shop',
            'zoho_oauth_host',
        ]);

        // Return to the embedded Shopify app with context preserved
        return redirect()->route('zoho.settings', [
            'shop' => $shop,
            'host' => $host,
        ]);
    }
}