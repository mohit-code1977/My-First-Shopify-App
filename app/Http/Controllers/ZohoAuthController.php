<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Shop;
use App\Models\ZohoConnection;

class ZohoAuthController extends Controller{
    public function connect(){
        $params = http_build_query([
            'client_id' => env('ZOHO_CLIENT_ID'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => 'ZohoBooks.settings.READ,ZohoBooks.settings.CREATE,ZohoBooks.settings.UPDATE',
            'redirect_uri' => env('ZOHO_REDIRECT_URI'),
        ]);

        $url = env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/auth?' . $params;

        return redirect($url);
    }

 public function callback(Request $request){
    $code = $request->query('code');

    if (!$code) {
        return response()->json([
            'error' => 'Authorization code missing'
        ], 400);
    }

    // 1. Exchange authorization code for Zoho tokens
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

    // 2. Get Zoho Books organizations
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

    // 3. Select the default organization
    $organization = collect($organizations)
        ->firstWhere('is_default_org', true)
        ?? $organizations[0];

    $organizationId = $organization['organization_id'];

    // 4. Get our Shopify shop
    $shop = Shop::first();

    if (!$shop) {
        return response()->json([
            'error' => 'No Shopify shop installed'
        ], 404);
    }

    // 5. Save / update Zoho connection
    $zohoConnection = ZohoConnection::updateOrCreate(
        [
            'shop_id' => $shop->id,
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

    // 6. Success response
    return response()->json([
        'message' => 'Zoho connected successfully',
        'shop' => $shop->shop_domain,
        'organization' => $organization['name'],
        'organization_id' => $organizationId,
        'token_saved' => true,
        'refresh_token_saved' => true,
        'expires_at' => $zohoConnection->expires_at,
    ]);
}
}